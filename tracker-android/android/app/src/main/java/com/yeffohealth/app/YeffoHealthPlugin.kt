package com.yeffohealth.app

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import androidx.activity.result.ActivityResult
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.PermissionController
import androidx.health.connect.client.permission.HealthPermission
import androidx.health.connect.client.records.BodyFatRecord
import androidx.health.connect.client.records.HydrationRecord
import androidx.health.connect.client.records.NutritionRecord
import androidx.health.connect.client.records.WeightRecord
import androidx.health.connect.client.request.AggregateGroupByPeriodRequest
import androidx.health.connect.client.request.ReadRecordsRequest
import androidx.health.connect.client.time.TimeRangeFilter
import com.getcapacitor.JSArray
import com.getcapacitor.JSObject
import com.getcapacitor.Plugin
import com.getcapacitor.PluginCall
import com.getcapacitor.PluginMethod
import com.getcapacitor.annotation.ActivityCallback
import com.getcapacitor.annotation.CapacitorPlugin
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.LocalDateTime
import java.time.Period
import java.time.ZoneId

/**
 * What the web tracker (yeffoprint-core/assets/tracker/tracker.js, "Android
 * app extras") calls on the phone, through Capacitor's bridge:
 *
 *   healthAvailability  is Health Connect here: available | update | unavailable
 *   healthRequest       ask to read weight, body fat, nutrition and water
 *   healthRead          those records since a time (food as day totals)
 *   healthSettings      open Health Connect's own permissions screen
 *   healthInstall       open Health Connect on Google Play
 *   widgetUpdate        what the Home Screen widget shows (YeffoWidget)
 *
 * Read only: nothing is ever written to Health Connect.
 */
@CapacitorPlugin(name = "YeffoHealth")
class YeffoHealthPlugin : Plugin() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

    private val permissions = setOf(
        HealthPermission.getReadPermission(WeightRecord::class),
        HealthPermission.getReadPermission(BodyFatRecord::class),
        HealthPermission.getReadPermission(NutritionRecord::class),
        HealthPermission.getReadPermission(HydrationRecord::class),
    )

    override fun handleOnDestroy() {
        scope.cancel()
        super.handleOnDestroy()
    }

    private fun client(): HealthConnectClient? =
        if (HealthConnectClient.getSdkStatus(context, PROVIDER) == HealthConnectClient.SDK_AVAILABLE) {
            HealthConnectClient.getOrCreate(context)
        } else {
            null
        }

    @PluginMethod
    fun healthAvailability(call: PluginCall) {
        val status = when (HealthConnectClient.getSdkStatus(context, PROVIDER)) {
            HealthConnectClient.SDK_AVAILABLE -> "available"
            HealthConnectClient.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED -> "update"
            else -> "unavailable"
        }
        call.resolve(JSObject().put("status", status))
    }

    @PluginMethod
    fun healthRequest(call: PluginCall) {
        if (client() == null) {
            call.reject("Health Connect isn’t available on this phone.")
            return
        }
        val intent = PermissionController.createRequestPermissionResultContract(PROVIDER)
            .createIntent(context, permissions)
        startActivityForResult(call, intent, "afterPermissions")
    }

    @ActivityCallback
    private fun afterPermissions(call: PluginCall?, result: ActivityResult) {
        if (call == null) {
            return
        }
        resolveGranted(call)
    }

    private fun resolveGranted(call: PluginCall) {
        val hc = client() ?: return call.reject("Health Connect isn’t available on this phone.")
        scope.launch {
            try {
                val granted = hc.permissionController.getGrantedPermissions().intersect(permissions)
                val names = JSArray()
                granted.forEach { p -> names.put(KINDS[p] ?: p) }
                call.resolve(JSObject().put("granted", names))
            } catch (e: Exception) {
                call.reject(e.message ?: "Health Connect couldn’t be read.")
            }
        }
    }

    @PluginMethod
    fun healthPermissions(call: PluginCall) {
        resolveGranted(call)
    }

    @PluginMethod
    fun healthRead(call: PluginCall) {
        val hc = client() ?: return call.reject("Health Connect isn’t available on this phone.")
        val since = try {
            Instant.parse(call.getString("since") ?: "")
        } catch (e: Exception) {
            Instant.now().minusSeconds(30L * 86400)
        }
        val now = Instant.now()
        scope.launch {
            try {
                val granted = hc.permissionController.getGrantedPermissions()
                val out = JSObject()
                val range = TimeRangeFilter.between(since, now)

                if (HealthPermission.getReadPermission(WeightRecord::class) in granted) {
                    val list = JSArray()
                    readAll(hc, WeightRecord::class, range).forEach { r ->
                        list.put(JSObject().put("at", r.time.toString()).put("kg", r.weight.inKilograms))
                    }
                    out.put("weight", list)
                }
                if (HealthPermission.getReadPermission(BodyFatRecord::class) in granted) {
                    val list = JSArray()
                    readAll(hc, BodyFatRecord::class, range).forEach { r ->
                        list.put(JSObject().put("at", r.time.toString()).put("pct", r.percentage.value))
                    }
                    out.put("fat", list)
                }

                // Food and water as whole local days. Health Connect's own
                // totals, so two apps logging the same meal aren't counted twice.
                val zone = ZoneId.systemDefault()
                val dayRange = TimeRangeFilter.between(
                    LocalDateTime.ofInstant(since, zone),
                    LocalDateTime.ofInstant(now, zone),
                )
                val metrics = buildSet {
                    if (HealthPermission.getReadPermission(NutritionRecord::class) in granted) {
                        add(NutritionRecord.PROTEIN_TOTAL)
                        add(NutritionRecord.ENERGY_TOTAL)
                    }
                    if (HealthPermission.getReadPermission(HydrationRecord::class) in granted) {
                        add(HydrationRecord.VOLUME_TOTAL)
                    }
                }
                if (metrics.isNotEmpty()) {
                    val protein = JSArray()
                    val energy = JSArray()
                    val water = JSArray()
                    hc.aggregateGroupByPeriod(AggregateGroupByPeriodRequest(metrics, dayRange, Period.ofDays(1))).forEach { day ->
                        val at = day.startTime.atZone(zone).toInstant().toString()
                        day.result[NutritionRecord.PROTEIN_TOTAL]?.let { protein.put(JSObject().put("at", at).put("g", it.inGrams)) }
                        day.result[NutritionRecord.ENERGY_TOTAL]?.let { energy.put(JSObject().put("at", at).put("kcal", it.inKilocalories)) }
                        day.result[HydrationRecord.VOLUME_TOTAL]?.let { water.put(JSObject().put("at", at).put("mL", it.inMilliliters)) }
                    }
                    out.put("protein", protein)
                    out.put("energy", energy)
                    out.put("water", water)
                }
                call.resolve(out)
            } catch (e: SecurityException) {
                call.reject("Health Connect permission was turned off. Connect again from Me.")
            } catch (e: Exception) {
                call.reject(e.message ?: "Health Connect couldn’t be read.")
            }
        }
    }

    private suspend fun <T : androidx.health.connect.client.records.Record> readAll(
        hc: HealthConnectClient,
        type: kotlin.reflect.KClass<T>,
        range: TimeRangeFilter,
    ): List<T> {
        val all = mutableListOf<T>()
        var token: String? = null
        do {
            val page = hc.readRecords(ReadRecordsRequest(type, range, pageSize = 500, pageToken = token))
            all.addAll(page.records)
            token = page.pageToken
        } while (!token.isNullOrEmpty() && all.size < 5000)
        return all
    }

    @PluginMethod
    fun healthSettings(call: PluginCall) {
        try {
            activity.startActivity(Intent(HealthConnectClient.ACTION_HEALTH_CONNECT_SETTINGS))
            call.resolve()
        } catch (e: ActivityNotFoundException) {
            call.reject("Health Connect isn’t available on this phone.")
        }
    }

    @PluginMethod
    fun healthInstall(call: PluginCall) {
        val uri = Uri.parse("market://details?id=$PROVIDER&url=healthconnect%3A%2F%2Fonboarding")
        try {
            activity.startActivity(Intent(Intent.ACTION_VIEW, uri).setPackage("com.android.vending"))
        } catch (e: ActivityNotFoundException) {
            activity.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("https://play.google.com/store/apps/details?id=$PROVIDER")))
        }
        call.resolve()
    }

    @PluginMethod
    fun widgetUpdate(call: PluginCall) {
        YeffoWidget.save(context, call.getString("data") ?: "")
        call.resolve()
    }

    companion object {
        private const val PROVIDER = "com.google.android.apps.healthdata"

        private val KINDS = mapOf(
            HealthPermission.getReadPermission(WeightRecord::class) to "weight",
            HealthPermission.getReadPermission(BodyFatRecord::class) to "fat",
            HealthPermission.getReadPermission(NutritionRecord::class) to "nutrition",
            HealthPermission.getReadPermission(HydrationRecord::class) to "hydration",
        )
    }
}

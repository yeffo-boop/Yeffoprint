package com.yeffohealth.app

import android.app.AlarmManager
import android.app.PendingIntent
import android.appwidget.AppWidgetManager
import android.appwidget.AppWidgetProvider
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.text.format.DateFormat
import android.view.View
import android.widget.RemoteViews
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale

/**
 * The YeffoHealth Home Screen widget: today's doses taken and the next
 * one due. The web tracker sends what to show (tracker.js widgetData())
 * every time it changes, so the widget never reads the encrypted tracker
 * itself. It works out "next" from those times on each refresh: every
 * 30 minutes, and once just after the next dose time.
 */
class YeffoWidget : AppWidgetProvider() {

    override fun onUpdate(context: Context, manager: AppWidgetManager, ids: IntArray) {
        ids.forEach { manager.updateAppWidget(it, views(context)) }
        scheduleNext(context)
    }

    companion object {
        private const val PREFS = "yeffohealth_widget"
        private const val KEY = "data"
        // A dose stays "due" on the widget this long after its time, until it's logged.
        private const val OVERDUE_MS = 4 * 60 * 60 * 1000L

        fun save(context: Context, data: String) {
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString(KEY, data).apply()
            refresh(context)
        }

        fun refresh(context: Context) {
            val manager = AppWidgetManager.getInstance(context)
            val ids = manager.getAppWidgetIds(ComponentName(context, YeffoWidget::class.java))
            if (ids.isEmpty()) {
                return
            }
            ids.forEach { manager.updateAppWidget(it, views(context)) }
            scheduleNext(context)
        }

        private fun data(context: Context): JSONObject? {
            val raw = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY, "") ?: ""
            return try {
                if (raw.isEmpty()) null else JSONObject(raw)
            } catch (e: Exception) {
                null
            }
        }

        private fun views(context: Context): RemoteViews {
            val v = RemoteViews(context.packageName, R.layout.yeffo_widget)
            val open = PendingIntent.getActivity(
                context, 0,
                Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
            )
            v.setOnClickPendingIntent(R.id.widget_root, open)

            val d = data(context)
            if (d == null) {
                v.setTextViewText(R.id.widget_count, "")
                v.setTextViewText(R.id.widget_next_label, "")
                v.setTextViewText(R.id.widget_name, context.getString(R.string.widget_signed_out))
                v.setTextViewText(R.id.widget_sub, context.getString(R.string.widget_signed_out_sub))
                v.setViewVisibility(R.id.widget_dot, View.GONE)
                return v
            }

            val today = SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date())
            val due = d.optInt("due", 0)
            v.setTextViewText(
                R.id.widget_count,
                if (d.optString("date") == today && due > 0) context.getString(R.string.widget_count, d.optInt("done", 0), due) else "",
            )

            val now = System.currentTimeMillis()
            val items = d.optJSONArray("items")
            var next: JSONObject? = null
            if (items != null) {
                for (i in 0 until items.length()) {
                    val it = items.optJSONObject(i) ?: continue
                    if (it.optLong("at") >= now - OVERDUE_MS) {
                        next = it
                        break
                    }
                }
            }
            if (next == null) {
                v.setTextViewText(R.id.widget_next_label, "")
                v.setTextViewText(R.id.widget_name, context.getString(R.string.widget_all_done))
                v.setTextViewText(R.id.widget_sub, context.getString(R.string.widget_all_done_sub))
                v.setViewVisibility(R.id.widget_dot, View.GONE)
                return v
            }

            val at = next.optLong("at")
            val time = DateFormat.getTimeFormat(context).format(Date(at))
            val whenText = when {
                at < now -> context.getString(R.string.widget_due_since, time)
                sameDay(at, now) -> time
                else -> context.getString(R.string.widget_tomorrow, time)
            }
            val names = d.optBoolean("names", true)
            v.setTextViewText(R.id.widget_next_label, context.getString(if (at < now) R.string.widget_due else R.string.widget_next))
            v.setTextViewText(R.id.widget_name, if (names) next.optString("name") else context.getString(R.string.widget_dose_due))
            val dose = next.optString("dose")
            v.setTextViewText(R.id.widget_sub, if (names && dose.isNotEmpty()) "$dose · $whenText" else whenText)
            v.setViewVisibility(R.id.widget_dot, View.VISIBLE)
            val color = try {
                Color.parseColor(next.optString("color", "#EC008C"))
            } catch (e: Exception) {
                Color.parseColor("#EC008C")
            }
            v.setInt(R.id.widget_dot, "setColorFilter", color)
            return v
        }

        private fun sameDay(a: Long, b: Long): Boolean {
            val ca = Calendar.getInstance().apply { timeInMillis = a }
            val cb = Calendar.getInstance().apply { timeInMillis = b }
            return ca.get(Calendar.YEAR) == cb.get(Calendar.YEAR) && ca.get(Calendar.DAY_OF_YEAR) == cb.get(Calendar.DAY_OF_YEAR)
        }

        /** One inexact wake-up just after the next dose time (or when an overdue one stops showing), so "next" moves on. */
        private fun scheduleNext(context: Context) {
            val items = data(context)?.optJSONArray("items") ?: return
            val now = System.currentTimeMillis()
            var soonest = Long.MAX_VALUE
            for (i in 0 until items.length()) {
                val at = items.optJSONObject(i)?.optLong("at") ?: continue
                listOf(at + 60_000L, at + OVERDUE_MS + 60_000L).forEach { t ->
                    if (t > now && t < soonest) {
                        soonest = t
                    }
                }
            }
            if (soonest == Long.MAX_VALUE) {
                return
            }
            val intent = Intent(context, YeffoWidget::class.java).setAction(AppWidgetManager.ACTION_APPWIDGET_UPDATE)
                .putExtra(
                    AppWidgetManager.EXTRA_APPWIDGET_IDS,
                    AppWidgetManager.getInstance(context).getAppWidgetIds(ComponentName(context, YeffoWidget::class.java)),
                )
            val pending = PendingIntent.getBroadcast(context, 1, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
            val alarms = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
            alarms.set(AlarmManager.RTC, soonest, pending)
        }
    }
}

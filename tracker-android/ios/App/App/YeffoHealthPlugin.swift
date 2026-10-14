import Foundation
import UIKit
import Capacitor
import HealthKit
import WidgetKit

/// The app's own native plugin, the iPhone side of YeffoHealthPlugin.kt:
/// Apple Health (read only: weight, body fat, protein, calories, water)
/// and the Home Screen widget. tracker.js calls it as "YeffoHealth" with
/// the same method names and result shapes as Android's Health Connect.
@objc(YeffoHealthPlugin)
public class YeffoHealthPlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "YeffoHealthPlugin"
    public let jsName = "YeffoHealth"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "healthAvailability", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "healthRequest", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "healthPermissions", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "healthRead", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "healthSettings", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "healthInstall", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "widgetUpdate", returnType: CAPPluginReturnPromise)
    ]

    /// Shared with the widget extension (both targets' entitlements).
    static let appGroup = "group.com.yeffohealth.app"
    static let widgetKey = "widgetData"

    private let store = HKHealthStore()

    private var types: [String: HKQuantityType] {
        var t: [String: HKQuantityType] = [:]
        if let x = HKQuantityType.quantityType(forIdentifier: .bodyMass) { t["weight"] = x }
        if let x = HKQuantityType.quantityType(forIdentifier: .bodyFatPercentage) { t["fat"] = x }
        if let x = HKQuantityType.quantityType(forIdentifier: .dietaryProtein) { t["protein"] = x }
        if let x = HKQuantityType.quantityType(forIdentifier: .dietaryEnergyConsumed) { t["energy"] = x }
        if let x = HKQuantityType.quantityType(forIdentifier: .dietaryWater) { t["water"] = x }
        return t
    }

    @objc func healthAvailability(_ call: CAPPluginCall) {
        call.resolve(["status": HKHealthStore.isHealthDataAvailable() ? "available" : "unavailable"])
    }

    @objc func healthRequest(_ call: CAPPluginCall) {
        guard HKHealthStore.isHealthDataAvailable() else {
            call.reject("Apple Health isn’t available on this device.")
            return
        }
        store.requestAuthorization(toShare: nil, read: Set(types.values.map { $0 as HKObjectType })) { ok, error in
            if let error = error {
                call.reject(error.localizedDescription)
                return
            }
            // iPhone never says which reads were allowed (a "no" just reads
            // as no data), so a finished sheet counts as all of them.
            call.resolve(["granted": ok ? Array(self.types.keys).map(self.kind) : []])
        }
    }

    @objc func healthPermissions(_ call: CAPPluginCall) {
        call.resolve(["granted": Array(types.keys).map(kind)])
    }

    /// Android's names for the permission kinds ("protein"/"energy" are both nutrition).
    private func kind(_ key: String) -> String {
        switch key {
        case "protein", "energy": return "nutrition"
        case "water": return "hydration"
        default: return key
        }
    }

    @objc func healthRead(_ call: CAPPluginCall) {
        let iso = ISO8601DateFormatter()
        let now = Date()
        let since = call.getString("since").flatMap { s -> Date? in
            let f = ISO8601DateFormatter()
            f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
            return f.date(from: s) ?? iso.date(from: s)
        } ?? now.addingTimeInterval(-30 * 86400)

        var out: [String: Any] = [:]
        let lock = NSLock()
        let group = DispatchGroup()

        // Weigh-ins and body fat: every sample, like Health Connect's records.
        let samples: [(String, String, HKUnit, Double)] = [
            ("weight", "kg", HKUnit.gramUnit(with: .kilo), 1),
            ("fat", "pct", HKUnit.percent(), 100)
        ]
        for (key, field, unit, scale) in samples {
            guard let type = types[key] else { continue }
            group.enter()
            let predicate = HKQuery.predicateForSamples(withStart: since, end: now, options: [])
            let sort = NSSortDescriptor(key: HKSampleSortIdentifierStartDate, ascending: true)
            let q = HKSampleQuery(sampleType: type, predicate: predicate, limit: 5000, sortDescriptors: [sort]) { _, results, _ in
                let list: [[String: Any]] = (results as? [HKQuantitySample] ?? []).map {
                    ["at": iso.string(from: $0.startDate), field: $0.quantity.doubleValue(for: unit) * scale]
                }
                lock.lock()
                out[key] = list
                lock.unlock()
                group.leave()
            }
            store.execute(q)
        }

        // Food and water: Apple Health's own daily totals, local days.
        let cal = Calendar.current
        let start = cal.startOfDay(for: since)
        let totals: [(String, String, HKUnit)] = [
            ("protein", "g", HKUnit.gram()),
            ("energy", "kcal", HKUnit.kilocalorie()),
            ("water", "mL", HKUnit.literUnit(with: .milli))
        ]
        for (key, field, unit) in totals {
            guard let type = types[key] else { continue }
            group.enter()
            let q = HKStatisticsCollectionQuery(
                quantityType: type,
                quantitySamplePredicate: HKQuery.predicateForSamples(withStart: start, end: now, options: []),
                options: .cumulativeSum,
                anchorDate: start,
                intervalComponents: DateComponents(day: 1)
            )
            q.initialResultsHandler = { _, results, _ in
                var list: [[String: Any]] = []
                results?.enumerateStatistics(from: start, to: now) { stat, _ in
                    if let sum = stat.sumQuantity() {
                        list.append(["at": iso.string(from: stat.startDate), field: sum.doubleValue(for: unit)])
                    }
                }
                lock.lock()
                out[key] = list
                lock.unlock()
                group.leave()
            }
            store.execute(q)
        }

        group.notify(queue: .main) {
            call.resolve(out)
        }
    }

    /// Health permissions live in the app's page in Settings (Health › Data Access also has them).
    @objc func healthSettings(_ call: CAPPluginCall) {
        DispatchQueue.main.async {
            if let url = URL(string: UIApplication.openSettingsURLString) {
                UIApplication.shared.open(url)
            }
            call.resolve()
        }
    }

    /// Android opens Google Play to update Health Connect; Apple Health is built in.
    @objc func healthInstall(_ call: CAPPluginCall) {
        call.resolve()
    }

    @objc func widgetUpdate(_ call: CAPPluginCall) {
        let data = call.getString("data") ?? ""
        let defaults = UserDefaults(suiteName: YeffoHealthPlugin.appGroup)
        if data.isEmpty {
            defaults?.removeObject(forKey: YeffoHealthPlugin.widgetKey)
        } else {
            defaults?.set(data, forKey: YeffoHealthPlugin.widgetKey)
        }
        if #available(iOS 14.0, *) {
            WidgetCenter.shared.reloadAllTimelines()
        }
        call.resolve()
    }
}

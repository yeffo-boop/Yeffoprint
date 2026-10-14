import WidgetKit
import SwiftUI

/// The YeffoHealth Home Screen widget, the iPhone twin of YeffoWidget.kt:
/// today's doses taken and the next one due. The tracker sends what to
/// show (tracker.js widgetData(), via YeffoHealthPlugin.widgetUpdate)
/// into the shared app group, so the widget never reads the encrypted
/// tracker itself.

private let appGroup = "group.com.yeffohealth.app"
private let widgetKey = "widgetData"
/// A dose stays "due" on the widget this long after its time, until it's logged.
private let overdue: TimeInterval = 4 * 60 * 60
private let magenta = Color(red: 0xEC / 255, green: 0, blue: 0x8C / 255)

struct WidgetItem: Decodable {
    let at: Double // ms
    let name: String?
    let dose: String?
    let color: String?
}

struct WidgetPayload: Decodable {
    let date: String?
    let done: Int?
    let due: Int?
    let names: Bool?
    let items: [WidgetItem]?
}

struct DoseEntry: TimelineEntry {
    let date: Date
    let payload: WidgetPayload?
}

struct DoseProvider: TimelineProvider {
    func placeholder(in context: Context) -> DoseEntry {
        DoseEntry(date: Date(), payload: WidgetPayload(date: nil, done: 1, due: 3, names: true, items: [
            WidgetItem(at: Date().addingTimeInterval(3600).timeIntervalSince1970 * 1000, name: "Vitamin D", dose: "1 capsule", color: "#00AEEF")
        ]))
    }

    func getSnapshot(in context: Context, completion: @escaping (DoseEntry) -> Void) {
        completion(context.isPreview ? placeholder(in: context) : DoseEntry(date: Date(), payload: load()))
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<DoseEntry>) -> Void) {
        let payload = load()
        let now = Date()
        // A fresh entry just after each dose time and when an overdue one drops off, so "next" moves on.
        var dates = [now]
        for item in payload?.items ?? [] {
            let at = Date(timeIntervalSince1970: item.at / 1000)
            for t in [at.addingTimeInterval(60), at.addingTimeInterval(overdue + 60)] where t > now {
                dates.append(t)
            }
        }
        let entries = Array(Set(dates)).sorted().prefix(24).map { DoseEntry(date: $0, payload: payload) }
        completion(Timeline(entries: entries, policy: .after(now.addingTimeInterval(30 * 60))))
    }

    private func load() -> WidgetPayload? {
        guard let raw = UserDefaults(suiteName: appGroup)?.string(forKey: widgetKey), let data = raw.data(using: .utf8) else {
            return nil
        }
        return try? JSONDecoder().decode(WidgetPayload.self, from: data)
    }
}

struct YeffoWidgetView: View {
    let entry: DoseEntry

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text("YeffoHealth").font(.caption2.weight(.semibold)).foregroundColor(.secondary)
                Spacer()
                if let count = countText {
                    Text(count).font(.caption2.weight(.semibold)).foregroundColor(magenta)
                }
            }
            Spacer(minLength: 0)
            content
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .leading)
    }

    private var countText: String? {
        guard let p = entry.payload, let due = p.due, due > 0, p.date == dayString(entry.date) else {
            return nil
        }
        return "\(p.done ?? 0)/\(due) today"
    }

    @ViewBuilder private var content: some View {
        if let p = entry.payload {
            if let next = (p.items ?? []).first(where: { $0.at / 1000 >= entry.date.timeIntervalSince1970 - overdue }) {
                let at = Date(timeIntervalSince1970: next.at / 1000)
                let names = p.names ?? true
                Text(at < entry.date ? "Due now" : "Next").font(.caption).foregroundColor(.secondary)
                HStack(spacing: 6) {
                    Circle().fill(color(next.color)).frame(width: 8, height: 8)
                    Text(names ? (next.name ?? "Dose") : "Dose due").font(.headline).lineLimit(2)
                }
                Text(sub(next, at: at, names: names)).font(.caption).foregroundColor(.secondary).lineLimit(2)
            } else {
                Text("All caught up").font(.headline)
                Text("Nothing else due today or tomorrow").font(.caption).foregroundColor(.secondary)
            }
        } else {
            Text("Open YeffoHealth").font(.headline)
            Text("Sign in to see your next dose here").font(.caption).foregroundColor(.secondary)
        }
    }

    private func sub(_ item: WidgetItem, at: Date, names: Bool) -> String {
        let time = at.formatted(date: .omitted, time: .shortened)
        let when: String
        if at < entry.date {
            when = "Due at \(time)"
        } else if Calendar.current.isDate(at, inSameDayAs: entry.date) {
            when = time
        } else {
            when = "Tomorrow \(time)"
        }
        if names, let dose = item.dose, !dose.isEmpty {
            return "\(dose) · \(when)"
        }
        return when
    }

    private func dayString(_ d: Date) -> String {
        let f = DateFormatter()
        f.calendar = Calendar(identifier: .gregorian)
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        return f.string(from: d)
    }

    private func color(_ hex: String?) -> Color {
        guard let hex = hex, hex.hasPrefix("#"), hex.count == 7, let v = Int(hex.dropFirst(), radix: 16) else {
            return magenta
        }
        return Color(red: Double((v >> 16) & 0xFF) / 255, green: Double((v >> 8) & 0xFF) / 255, blue: Double(v & 0xFF) / 255)
    }
}

@main
struct YeffoWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: "YeffoWidget", provider: DoseProvider()) { entry in
            YeffoWidgetView(entry: entry)
                .containerBackground(.background, for: .widget)
        }
        .configurationDisplayName("Next dose")
        .description("Your next dose and how today is going")
        .supportedFamilies([.systemSmall, .systemMedium])
    }
}

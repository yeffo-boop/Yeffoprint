import UIKit
import Capacitor

/// The app's web view. Registers YeffoHealthPlugin, which lives in the app
/// itself rather than in an npm package, before the tracker loads.
class MainViewController: CAPBridgeViewController {
    override open func capacitorDidLoad() {
        bridge?.registerPluginInstance(YeffoHealthPlugin())
    }
}

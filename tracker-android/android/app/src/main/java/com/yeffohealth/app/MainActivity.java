package com.yeffohealth.app;

import android.os.Bundle;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // The app's own native plugin (Health Connect and the Home Screen widget) must register before the bridge starts.
        registerPlugin(YeffoHealthPlugin.class);
        super.onCreate(savedInstanceState);
    }
}

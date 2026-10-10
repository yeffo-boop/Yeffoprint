package com.yeffohealth.app;

import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;

/**
 * Health Connect opens this when someone taps "Privacy policy" next to
 * YeffoHealth in its permissions screen (required for every app that
 * asks for health permissions). It just opens the site's privacy policy.
 */
public class HealthPrivacyActivity extends Activity {

    private static final String POLICY_URL = "https://yeffodesign.com/privacy-policy/";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(POLICY_URL)));
        } catch (ActivityNotFoundException e) {
            // No browser: nothing more we can show.
        }
        finish();
    }
}

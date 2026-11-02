package za.co.grazex.app.push

import android.content.Context
import com.google.firebase.FirebaseApp
import za.co.grazex.app.BuildConfig

/** Push works only in a build made with google-services.json (see appfiles/README.md). */
object PushSupport {
    fun available(context: Context): Boolean = BuildConfig.PUSH_CONFIGURED && FirebaseApp.getApps(context).isNotEmpty()
}

package za.co.grazex.app

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import androidx.core.content.getSystemService

/** Creates the notification channels (people can switch each one off in the phone's settings). */
class GrazeXApp : Application() {
    override fun onCreate() {
        super.onCreate()
        val nm = getSystemService<NotificationManager>() ?: return
        nm.createNotificationChannels(
            listOf(
                NotificationChannel(Channels.ORDERS, getString(R.string.channel_orders), NotificationManager.IMPORTANCE_HIGH)
                    .apply { description = getString(R.string.channel_orders_desc) },
                NotificationChannel(Channels.LIVESTOCK, getString(R.string.channel_livestock), NotificationManager.IMPORTANCE_DEFAULT)
                    .apply { description = getString(R.string.channel_livestock_desc) },
                NotificationChannel(Channels.STAFF, getString(R.string.channel_staff), NotificationManager.IMPORTANCE_HIGH)
                    .apply { description = getString(R.string.channel_staff_desc) },
            ),
        )
    }
}

/** Must match PushService::CHANNELS on the server. */
object Channels {
    const val ORDERS = "orders"
    const val LIVESTOCK = "livestock"
    const val STAFF = "staff"
    val ALL = setOf(ORDERS, LIVESTOCK, STAFF)
}

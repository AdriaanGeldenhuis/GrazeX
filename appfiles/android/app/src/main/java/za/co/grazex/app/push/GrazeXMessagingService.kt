package za.co.grazex.app.push

import android.Manifest
import android.app.PendingIntent
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import za.co.grazex.app.BuildConfig
import za.co.grazex.app.Channels
import za.co.grazex.app.MainActivity
import za.co.grazex.app.R
import za.co.grazex.app.UrlPolicy

/**
 * Receives GrazeX push messages (data only — see PushService.php) and shows them as notifications.
 * Tapping one opens that page of the site in the app.
 */
class GrazeXMessagingService : FirebaseMessagingService() {

    /** Nothing to store: on the next page the site's bridge sees the new token and links it to the login. */
    @Suppress("OVERRIDE_DEPRECATION")
    override fun onNewToken(token: String) = Unit

    override fun onMessageReceived(message: RemoteMessage) {
        val data = message.data
        val title = data["title"] ?: message.notification?.title ?: return
        val body = data["body"] ?: message.notification?.body ?: ""
        val channel = data["channel"]?.takeIf { it in Channels.ALL } ?: Channels.ORDERS
        val tag = data["tag"]?.take(64) ?: title
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            return
        }
        // Only a page of our own site can be opened from a notification
        val target = UrlPolicy(BuildConfig.BASE_URL).deepLink(data["url"])
        val open = Intent(this, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            .apply { if (target != null) putExtra(MainActivity.EXTRA_URL, target) }
        val pending = PendingIntent.getActivity(
            this, tag.hashCode(), open, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        val notification = NotificationCompat.Builder(this, channel)
            .setSmallIcon(R.drawable.ic_notification)
            .setColor(ContextCompat.getColor(this, R.color.gx_orange))
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setAutoCancel(true)
            .setContentIntent(pending)
            .setCategory(if (channel == Channels.LIVESTOCK) NotificationCompat.CATEGORY_RECOMMENDATION else NotificationCompat.CATEGORY_STATUS)
            .build()
        try {
            // The same tag replaces the earlier notification about that order (no pile-up)
            NotificationManagerCompat.from(this).notify(tag, 0, notification)
        } catch (_: SecurityException) {
            // Notifications switched off between the check and now
        }
    }
}

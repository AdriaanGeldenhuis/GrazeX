package za.co.grazex.app

import android.app.DownloadManager
import android.os.Build
import android.os.Environment
import android.webkit.CookieManager
import android.webkit.DownloadListener
import android.webkit.URLUtil
import android.widget.Toast
import androidx.core.content.getSystemService
import androidx.core.net.toUri

/**
 * Downloads the page could not hand over through the bridge (an old WebView, or a very large file): the phone's
 * download manager fetches them. It gets the session cookie for the site, but never the "keep me logged in"
 * cookie: that one changes each time it is used, and the download manager could not give the new value back.
 */
class DownloadFallback(private val activity: MainActivity) : DownloadListener {

    override fun onDownloadStart(url: String, userAgent: String, contentDisposition: String?, mimetype: String?, contentLength: Long) {
        val policy = activity.policy
        val uri = url.toUri()
        val allowed = uri.scheme == "https" || (uri.scheme == "http" && policy.isSite(url) && policy.scheme == "http")
        if (!allowed) {
            Toast.makeText(activity, R.string.cannot_download, Toast.LENGTH_SHORT).show()
            return
        }
        val mime = FileNames.mime(mimetype)
        val name = FileNames.safe(URLUtil.guessFileName(url, contentDisposition, mime), mime)
        val request = DownloadManager.Request(uri)
            .setMimeType(mime)
            .setTitle(name)
            .addRequestHeader("User-Agent", userAgent)
            .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
        if (Build.VERSION.SDK_INT >= 29) {
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, "GrazeX/$name")
        } else {
            request.setDestinationInExternalFilesDir(activity, Environment.DIRECTORY_DOWNLOADS, "GrazeX/$name")
        }
        if (policy.isSite(url)) {
            val cookies = CookieManager.getInstance().getCookie(url).orEmpty()
                .split(';').map { it.trim() }.filter { it.isNotEmpty() && !it.startsWith("GXREMEMBER=") }
            if (cookies.isNotEmpty()) request.addRequestHeader("Cookie", cookies.joinToString("; "))
        }
        try {
            activity.getSystemService<DownloadManager>()?.enqueue(request)
            Toast.makeText(activity, activity.getString(R.string.downloading, name), Toast.LENGTH_SHORT).show()
        } catch (_: Exception) {
            Toast.makeText(activity, R.string.cannot_download, Toast.LENGTH_SHORT).show()
        }
    }
}

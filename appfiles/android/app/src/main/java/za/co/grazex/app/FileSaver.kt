package za.co.grazex.app

import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.ContentValues
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import android.widget.Toast
import androidx.core.content.FileProvider
import java.io.File
import java.io.IOException

/**
 * Saves a document from the site (invoice, receipt, proof of payment, the privacy data download) where people find it:
 * Downloads/GrazeX on Android 10+, the app's own Downloads folder on Android 8–9 (no storage permission needed).
 */
object FileSaver {
    data class Saved(val uri: Uri, val name: String, val mime: String)

    fun save(context: Context, name: String, mime: String, bytes: ByteArray): Saved {
        if (Build.VERSION.SDK_INT >= 29) {
            val resolver = context.contentResolver
            val values = ContentValues().apply {
                put(MediaStore.Downloads.DISPLAY_NAME, name)
                put(MediaStore.Downloads.MIME_TYPE, mime)
                put(MediaStore.Downloads.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS + "/GrazeX")
                put(MediaStore.Downloads.IS_PENDING, 1)
            }
            val uri = resolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values) ?: throw IOException("Could not create the file")
            try {
                (resolver.openOutputStream(uri) ?: throw IOException("Could not write the file")).use { it.write(bytes) }
                resolver.update(uri, ContentValues().apply { put(MediaStore.Downloads.IS_PENDING, 0) }, null, null)
            } catch (e: Exception) {
                resolver.delete(uri, null, null)
                throw e
            }
            // The phone may have renamed it ("invoice (1).pdf") when the name was taken
            val shown = resolver.query(uri, arrayOf(MediaStore.Downloads.DISPLAY_NAME), null, null, null)?.use { c ->
                if (c.moveToFirst()) c.getString(0) else null
            } ?: name
            return Saved(uri, shown, mime)
        }
        val root = context.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS) ?: File(context.filesDir, "Download")
        val dir = File(root, "GrazeX").apply { mkdirs() }
        var file = File(dir, name)
        var n = 1
        while (file.exists()) {
            val dot = name.lastIndexOf('.')
            file = File(dir, if (dot > 0) "${name.substring(0, dot)} ($n)${name.substring(dot)}" else "$name ($n)")
            n++
        }
        file.writeBytes(bytes)
        return Saved(FileProvider.getUriForFile(context, context.packageName + ".files", file), file.name, mime)
    }

    /** Open it in the phone's PDF viewer / gallery; without one, just say where it is. */
    fun open(activity: Activity, saved: Saved) {
        val view = Intent(Intent.ACTION_VIEW).setDataAndType(saved.uri, saved.mime).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        try {
            activity.startActivity(view)
        } catch (_: ActivityNotFoundException) {
            Toast.makeText(activity, activity.getString(R.string.saved_to_downloads, saved.name), Toast.LENGTH_LONG).show()
        }
    }
}

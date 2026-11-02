package za.co.grazex.app

/** Safe file names for saved documents. Plain Kotlin, unit-tested (FileNamesTest). */
object FileNames {
    private val extensions = mapOf(
        "application/pdf" to "pdf", "image/jpeg" to "jpg", "image/png" to "png", "image/webp" to "webp",
        "application/json" to "json", "text/csv" to "csv",
    )

    /** A MIME type we trust from the site, else a generic one. */
    fun mime(mime: String?): String {
        val m = mime?.substringBefore(';')?.trim()?.lowercase().orEmpty()
        return if (Regex("^[a-z]+/[a-z0-9.+-]+$").matches(m)) m else "application/octet-stream"
    }

    /** No folders, no characters other apps choke on, a sensible length, and an extension. */
    fun safe(name: String?, mime: String?): String {
        var n = name.orEmpty().substringAfterLast('/').substringAfterLast('\\')
        n = n.replace(Regex("[\\u0000-\\u001f\\u007f\"*:<>?|%]"), "_").trim().trimStart('.').trim()
        if (n.isEmpty()) n = "GrazeX-document"
        val ext = extensions[mime(mime)]
        if (ext != null && !n.lowercase().endsWith(".$ext") && !n.contains('.')) n = "$n.$ext"
        if (n.length > 100) {
            val dot = n.lastIndexOf('.')
            val suffix = if (dot in 1 until n.length && n.length - dot <= 10) n.substring(dot) else ""
            n = n.substring(0, 100 - suffix.length) + suffix
        }
        return n
    }
}

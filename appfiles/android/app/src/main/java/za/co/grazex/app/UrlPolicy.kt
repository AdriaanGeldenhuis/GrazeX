package za.co.grazex.app

import java.net.URI

/**
 * Where a link opens. Plain Kotlin (java.net.URI), so it is unit-tested on the JVM (UrlPolicyTest).
 *
 *  - The site (www and without www) stays in the app; http is upgraded to https when the site is https.
 *  - The card payment pages (Yoco) and, while paying, the bank's 3-D Secure pages stay in the app (https only),
 *    so the customer comes back to the site's payment page afterwards.
 *  - Every other website, and phone / email / WhatsApp / maps links, open in the right app outside.
 *  - Anything else (javascript:, file:, content:, data: …) is blocked.
 */
class UrlPolicy(baseUrl: String) {

    sealed interface Decision
    /** Load it in the app as it is. */
    data object InApp : Decision
    /** Load this address instead (http → https on the site). */
    data class Rewrite(val url: String) : Decision
    /** Hand it to another app (browser, phone, mail, WhatsApp, maps…). */
    data class External(val url: String) : Decision
    /** Do nothing. */
    data object Block : Decision

    private val base = URI(baseUrl.trimEnd('/'))
    val scheme: String = base.scheme.lowercase()
    val host: String = base.host.lowercase()
    private val port: Int = base.port
    private val hosts: Set<String> = setOf(host, if (host.startsWith("www.")) host.removePrefix("www.") else "www.$host")

    /** The site's home page. */
    val home: String = origin(host) + "/"

    private fun origin(h: String) = "$scheme://$h" + (if (port != -1) ":$port" else "")

    /** Origins the page bridge (GXNative) is offered to: the site only, never payment or video frames. */
    val bridgeOrigins: Set<String> = hosts.map { origin(it) }.toSet()

    private fun parse(url: String?): URI? {
        if (url.isNullOrBlank() || url.any { it.isWhitespace() || it.code < 0x20 }) return null
        return try {
            URI(url)
        } catch (_: Exception) {
            // Browsers leave | { } ^ ` and a lone % as they are in a query; java.net.URI refuses them
            try {
                URI(lenient(url))
            } catch (_: Exception) {
                null
            }
        }
    }

    private fun lenient(url: String): String = buildString {
        url.forEachIndexed { i, c ->
            when {
                c == '%' && !(i + 2 < url.length && url[i + 1].isHex() && url[i + 2].isHex()) -> append("%25")
                c in "|{}^`" -> append('%').append(c.code.toString(16).uppercase().padStart(2, '0'))
                else -> append(c)
            }
        }
    }

    private fun Char.isHex() = this in '0'..'9' || this in 'a'..'f' || this in 'A'..'F'

    private fun isSiteUri(u: URI): Boolean {
        val s = u.scheme?.lowercase() ?: return false
        val h = u.host?.lowercase() ?: return false
        if (h !in hosts) return false
        if (s != "http" && s != "https") return false
        // Same port as the site (a test server on :8080 is a different site from :80)
        val p = if (u.port == -1) defaultPort(s) else u.port
        val bp = if (port == -1) defaultPort(scheme) else port
        return p == bp || (s == "http" && scheme == "https" && u.port == -1)
    }

    private fun defaultPort(s: String) = if (s == "https") 443 else 80

    fun isSite(url: String?): Boolean = parse(url)?.let { isSiteUri(it) } ?: false

    /** The card payment pages (Yoco). */
    fun isPayment(url: String?): Boolean {
        val u = parse(url) ?: return false
        val h = u.host?.lowercase() ?: return false
        return u.scheme.equals("https", true) && (h == "yoco.com" || h.endsWith(".yoco.com"))
    }

    /**
     * A page the app is about to open (a tap, a redirect, a form).
     * @param paying the customer went to the card payment pages and has not come back to the site yet: the bank's
     *               3-D Secure pages (any https address) stay in the app, so the payment can finish here
     */
    fun decide(url: String, paying: Boolean = false): Decision {
        val u = parse(url) ?: return Block
        return when (u.scheme?.lowercase()) {
            "http", "https" -> when {
                isSiteUri(u) ->
                    if (u.scheme.equals("http", true) && scheme == "https") Rewrite("https" + url.substring(url.indexOf(':'))) else InApp
                isPayment(url) -> InApp
                paying && u.scheme.equals("https", true) -> InApp
                u.scheme.equals("https", true) -> External(url)
                else -> External(url) // an http page elsewhere: the browser decides
            }
            "tel", "mailto", "sms", "smsto", "geo", "whatsapp", "market", "intent" -> External(url)
            else -> Block
        }
    }

    /**
     * A link from outside the page — a notification, an App Link or grazex://app/<path>.
     * Returns the site address to open, or null when it is not a page of this site.
     */
    fun deepLink(link: String?): String? {
        if (link.isNullOrBlank()) return null
        if (link.startsWith("/")) return sitePath(link)
        val u = parse(link) ?: return null
        return when (u.scheme?.lowercase()) {
            "http", "https" -> if (isSiteUri(u)) sitePath((u.rawPath ?: "").ifEmpty { "/" } + (u.rawQuery?.let { "?$it" } ?: "") + (u.rawFragment?.let { "#$it" } ?: "")) else null
            "grazex" -> if (u.host.equals("app", true)) sitePath((u.rawPath ?: "").ifEmpty { "/" } + (u.rawQuery?.let { "?$it" } ?: "")) else null
            else -> null
        }
    }

    /** "/account/order.php?no=X" → the full site address; anything that could leave the site → null. */
    fun sitePath(path: String): String? {
        if (!path.startsWith("/") || path.startsWith("//") || path.contains('\\') || path.any { it.isWhitespace() || it.code < 0x20 }) return null
        return parse(origin(host) + path)?.let { if (isSiteUri(it)) it.toString() else null }
    }
}

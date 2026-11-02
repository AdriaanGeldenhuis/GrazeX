package za.co.grazex.app

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class UrlPolicyTest {
    private val live = UrlPolicy("https://www.grazex.co.za")
    private val local = UrlPolicy("http://10.0.2.2:8080")

    @Test
    fun siteStaysInTheApp() {
        assertEquals(UrlPolicy.InApp, live.decide("https://www.grazex.co.za/feeds/product/lucerne"))
        assertEquals(UrlPolicy.InApp, live.decide("https://grazex.co.za/ranch/"))
        assertEquals(UrlPolicy.InApp, live.decide("https://WWW.GRAZEX.CO.ZA/account/"))
    }

    @Test
    fun httpOnTheSiteBecomesHttps() {
        assertEquals(UrlPolicy.Rewrite("https://www.grazex.co.za/pages/terms"), live.decide("http://www.grazex.co.za/pages/terms"))
    }

    @Test
    fun aLocalTestServerKeepsItsAddress() {
        assertEquals(UrlPolicy.InApp, local.decide("http://10.0.2.2:8080/account/"))
        assertTrue(local.decide("http://10.0.2.2:9999/") is UrlPolicy.External)
        assertEquals(setOf("http://10.0.2.2:8080", "http://www.10.0.2.2:8080"), local.bridgeOrigins)
    }

    @Test
    fun lookalikeHostsAreNotTheSite() {
        assertTrue(live.decide("https://www.grazex.co.za.evil.com/") is UrlPolicy.External)
        assertTrue(live.decide("https://evilgrazex.co.za/") is UrlPolicy.External)
        assertTrue(live.decide("https://www.grazex.co.za@evil.com/") is UrlPolicy.External)
        assertFalse(live.isSite("https://grazex.co.za.evil.com"))
    }

    @Test
    fun paymentPagesStayInTheApp() {
        assertEquals(UrlPolicy.InApp, live.decide("https://payments.yoco.com/checkout/abc"))
        assertEquals(UrlPolicy.InApp, live.decide("https://c.yoco.com/x"))
        assertTrue(live.decide("https://notyoco.com/") is UrlPolicy.External)
        assertTrue(live.decide("http://payments.yoco.com/checkout/abc") is UrlPolicy.External)
        // The bank's 3-D Secure page only while paying
        assertTrue(live.decide("https://acs.somebank.co.za/3ds") is UrlPolicy.External)
        assertEquals(UrlPolicy.InApp, live.decide("https://acs.somebank.co.za/3ds", paying = true))
        assertTrue(live.decide("http://acs.somebank.co.za/3ds", paying = true) is UrlPolicy.External)
    }

    @Test
    fun otherAppsOpenOutside() {
        for (u in listOf("tel:+27821234567", "mailto:orders@grazex.co.za", "https://wa.me/27821234567", "geo:0,0?q=farm", "whatsapp://send?text=hi")) {
            assertTrue(u, live.decide(u) is UrlPolicy.External)
        }
    }

    @Test
    fun dangerousSchemesAreBlocked() {
        for (u in listOf("javascript:alert(1)", "file:///sdcard/x.pdf", "content://x/y", "data:text/html,hi", "about:blank", "", "not a url")) {
            assertEquals(u, UrlPolicy.Block, live.decide(u))
        }
    }

    @Test
    fun deepLinksOnlyOpenPagesOfTheSite() {
        assertEquals("https://www.grazex.co.za/account/order.php?no=GXF-0001", live.deepLink("/account/order.php?no=GXF-0001"))
        assertEquals("https://www.grazex.co.za/ranch/lot/merino-ewes", live.deepLink("https://grazex.co.za/ranch/lot/merino-ewes"))
        assertEquals("https://www.grazex.co.za/account/", live.deepLink("grazex://app/account/"))
        assertEquals("https://www.grazex.co.za/", live.deepLink("grazex://app"))
        assertNull(live.deepLink("//evil.com/x"))
        assertNull(live.deepLink("/\\evil.com"))
        assertNull(live.deepLink("https://evil.com/account/"))
        assertNull(live.deepLink("grazex://other/account/"))
        assertNull(live.deepLink("javascript:alert(1)"))
        assertNull(live.deepLink("/account/\nx"))
        assertNull(live.deepLink(null))
    }
}

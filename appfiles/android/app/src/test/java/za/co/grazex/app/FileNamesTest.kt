package za.co.grazex.app

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class FileNamesTest {
    @Test
    fun keepsAGoodName() {
        assertEquals("GXF-INV-0001.pdf", FileNames.safe("GXF-INV-0001.pdf", "application/pdf"))
    }

    @Test
    fun noFoldersOrOddCharacters() {
        assertEquals("passwd", FileNames.safe("../../etc/passwd", "application/octet-stream"))
        assertEquals("a_b_c.pdf", FileNames.safe("a:b?c.pdf", "application/pdf"))
        assertEquals("x.pdf", FileNames.safe("C:\\temp\\x.pdf", "application/pdf"))
        assertEquals("hidden.json", FileNames.safe(".hidden.json", "application/json"))
    }

    @Test
    fun addsTheExtensionAndANameWhenMissing() {
        assertEquals("receipt.pdf", FileNames.safe("receipt", "application/pdf"))
        assertEquals("GrazeX-document.jpg", FileNames.safe("", "image/jpeg"))
        assertEquals("GrazeX-document", FileNames.safe(null, null))
    }

    @Test
    fun longNamesKeepTheirExtension() {
        val n = FileNames.safe("a".repeat(300) + ".pdf", "application/pdf")
        assertEquals(100, n.length)
        assertTrue(n.endsWith(".pdf"))
    }

    @Test
    fun onlySensibleMimeTypes() {
        assertEquals("application/pdf", FileNames.mime("application/pdf; charset=binary"))
        assertEquals("application/octet-stream", FileNames.mime("text/html<script>"))
        assertEquals("application/octet-stream", FileNames.mime(null))
    }
}

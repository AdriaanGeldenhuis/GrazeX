# GrazeX — R8 rules for the release build.
# The app has no reflection or JavaScript interface classes of its own (the page talks to the app through
# WebViewCompat.addWebMessageListener), so the library rules that ship with AndroidX and Firebase are enough.

# Keep line numbers so crash reports from Play Console can be read (with the mapping file from the build)
-keepattributes SourceFile,LineNumberTable
-renamesourcefileattribute SourceFile

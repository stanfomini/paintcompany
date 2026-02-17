// Polyfills for Safari 17.x and older browsers that lack these APIs
// pdfjs-dist v5 requires URL.parse() and Promise.try()
if (typeof URL.parse !== 'function') {
    URL.parse = function(url, base) {
        try { return new URL(url, base); } catch { return null; }
    };
}
if (typeof Promise.try !== 'function') {
    Promise.try = function(fn) {
        return new Promise(function(resolve) { resolve(fn()); });
    };
}
importScripts('/pdf.worker.min.js');

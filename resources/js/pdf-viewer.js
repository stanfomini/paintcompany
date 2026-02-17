// Polyfills for Safari < 26 and older browsers
// Must run BEFORE pdfjs-dist loads (that's why we use dynamic import below)
if (typeof URL.parse !== 'function') {
    URL.parse = function(url, base) {
        try {
            return new URL(url, base);
        } catch {
            return null;
        }
    };
}

if (typeof Promise.try !== 'function') {
    Promise.try = function(fn, ...args) {
        return new Promise((resolve) => resolve(fn(...args)));
    };
}

// Dynamic import so polyfills above execute first (static imports are hoisted)
const pdfjsLib = await import('pdfjs-dist');

// Configure the worker - wrapper adds polyfills before loading actual worker
pdfjsLib.GlobalWorkerOptions.workerSrc = '/pdf.worker.wrapper.js';

/**
 * Load a PDF document from a URL.
 * @param {string} url - The URL to fetch the PDF from
 * @returns {Promise<import('pdfjs-dist').PDFDocumentProxy>}
 */
async function loadPdf(url) {
    const loadingTask = pdfjsLib.getDocument(url);
    return await loadingTask.promise;
}

/**
 * Render a single page of a PDF document to a canvas element.
 * @param {import('pdfjs-dist').PDFDocumentProxy} pdfDoc - The loaded PDF document
 * @param {number} pageNumber - 1-based page number
 * @param {HTMLCanvasElement} canvas - The canvas to render into
 * @param {number} [scale=1.5] - Scale factor for rendering
 * @returns {Promise<{width: number, height: number}>} The rendered page dimensions in PDF points
 */
async function renderPage(pdfDoc, pageNumber, canvas, scale = 1.5) {
    const page = await pdfDoc.getPage(pageNumber);
    const viewport = page.getViewport({ scale });

    canvas.height = viewport.height;
    canvas.width = viewport.width;

    const context = canvas.getContext('2d');

    await page.render({
        canvasContext: context,
        viewport: viewport,
    }).promise;

    // Return the original (unscaled) page dimensions in points
    const unscaledViewport = page.getViewport({ scale: 1 });
    return {
        width: unscaledViewport.width,
        height: unscaledViewport.height,
    };
}

// Expose globally for Alpine.js components
window.PdfViewer = { loadPdf, renderPage };

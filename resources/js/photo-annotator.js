import * as fabric from 'fabric';

// Make fabric available globally for Alpine.js components
window.fabric = fabric;

// Dispatch event so Livewire/Alpine components know fabric is ready
window.dispatchEvent(new CustomEvent('fabric-loaded'));

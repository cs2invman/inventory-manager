/**
 * Inventory Client-Side Filter System
 *
 * Provides real-time filtering of inventory items using a flexible query syntax.
 * Supports filtering by item name, float range, price range, pattern seed, wear category,
 * item category (StatTrak/Souvenir/Normal), collection, rarity, and storage box location.
 */

(function() {
    'use strict';

    // Configuration
    const DEBOUNCE_DELAY = 300; // milliseconds

    // DOM Elements
    let filterInput;
    let helpButton;
    let helpModal;
    let helpCloseButton;
    let helpBackdrop;
    let itemCards;
    let itemsGrid;
    let emptyState;
    let itemCountDisplay;
    let visibleCountSpan;
    let totalCountSpan;

    // State
    let debounceTimer = null;

    /**
     * Initialize the filter system when DOM is ready
     */
    function init() {
        // Get DOM elements
        filterInput = document.getElementById('inventory-filter-input');
        helpButton = document.getElementById('filter-help-button');
        helpModal = document.getElementById('filter-help-modal');
        helpCloseButton = document.getElementById('filter-help-close');
        helpBackdrop = document.getElementById('filter-help-backdrop');
        itemsGrid = document.getElementById('items-grid');
        emptyState = document.getElementById('filter-empty-state');
        itemCountDisplay = document.getElementById('filter-item-count');
        visibleCountSpan = document.getElementById('filter-visible-count');
        totalCountSpan = document.getElementById('filter-total-count');

        // Check if we're on the inventory page
        if (!filterInput || !itemsGrid) {
            return;
        }

        // Get all item cards
        itemCards = itemsGrid.querySelectorAll('.card');

        // Set initial total count
        if (totalCountSpan) {
            totalCountSpan.textContent = itemCards.length;
        }

        // Attach event listeners
        attachEventListeners();
    }

    /**
     * Attach all event listeners
     */
    function attachEventListeners() {
        // Filter input - debounced keyup
        if (filterInput) {
            filterInput.addEventListener('keyup', handleFilterInput);
            filterInput.addEventListener('keydown', handleFilterKeydown);
        }

        // Help button
        if (helpButton) {
            helpButton.addEventListener('click', showHelpModal);
        }

        // Help modal close
        if (helpCloseButton) {
            helpCloseButton.addEventListener('click', hideHelpModal);
        }
        if (helpBackdrop) {
            helpBackdrop.addEventListener('click', hideHelpModal);
        }

        // Escape key handlers
        document.addEventListener('keydown', handleEscapeKey);

        // Clear filter on tab navigation
        attachTabNavigationListeners();
    }

    /**
     * Handle filter input with debouncing
     */
    function handleFilterInput(event) {
        // Clear previous timer
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        // Set new timer
        debounceTimer = setTimeout(() => {
            applyFilter();
        }, DEBOUNCE_DELAY);
    }

    /**
     * Handle special keys in filter input
     */
    function handleFilterKeydown(event) {
        // Prevent form submission on Enter
        if (event.key === 'Enter') {
            event.preventDefault();
            applyFilter();
        }

        // Clear filter on Escape
        if (event.key === 'Escape') {
            filterInput.value = '';
            applyFilter();
        }
    }

    /**
     * Handle Escape key globally
     */
    function handleEscapeKey(event) {
        if (event.key === 'Escape') {
            // Close help modal if open
            if (helpModal && !helpModal.classList.contains('hidden')) {
                hideHelpModal();
            }
        }
    }

    /**
     * Show help modal
     */
    function showHelpModal() {
        if (helpModal) {
            helpModal.classList.remove('hidden');
            document.body.style.overflow = 'hidden'; // Prevent background scrolling
        }
    }

    /**
     * Hide help modal
     */
    function hideHelpModal() {
        if (helpModal) {
            helpModal.classList.add('hidden');
            document.body.style.overflow = ''; // Restore scrolling
        }
    }

    /**
     * Attach listeners to filter tabs for clearing filter
     */
    function attachTabNavigationListeners() {
        // Get all filter tab links
        const filterLinks = document.querySelectorAll('a[href*="filter="]');
        const storageBoxSelect = document.getElementById('storage-box-filter');

        filterLinks.forEach(link => {
            link.addEventListener('click', clearFilter);
        });

        if (storageBoxSelect) {
            storageBoxSelect.addEventListener('change', clearFilter);
        }
    }

    /**
     * Clear the filter input and reset visibility
     */
    function clearFilter() {
        if (filterInput) {
            filterInput.value = '';
        }
    }

    /**
     * Parse the filter query string into a filter object
     * @param {string} queryString - The raw filter query
     * @returns {object} Parsed filter object
     */
    function parseFilterQuery(queryString) {
        const filter = {};

        if (!queryString || queryString.trim() === '') {
            return filter;
        }

        let remainingQuery = queryString.toLowerCase().trim();

        // Extract numeric range: could be float or price depending on value
        const numericRangeMatch = remainingQuery.match(/([<>])(\d+\.?\d*)|(\d+\.?\d*)-(\d+\.?\d*)/);
        if (numericRangeMatch) {
            if (numericRangeMatch[1]) {
                // < or > operator
                const value = parseFloat(numericRangeMatch[2]);
                if (value <= 1.0) {
                    // Treat as float (floats are typically 0.0 to 1.0)
                    filter.floatRange = {
                        operator: numericRangeMatch[1],
                        value: value
                    };
                } else {
                    // Treat as price (prices are typically > 1.0)
                    filter.priceRange = {
                        operator: numericRangeMatch[1],
                        value: value
                    };
                }
            } else if (numericRangeMatch[3] && numericRangeMatch[4]) {
                // Range: min-max (always treat as float range)
                filter.floatRange = {
                    operator: 'range',
                    min: parseFloat(numericRangeMatch[3]),
                    max: parseFloat(numericRangeMatch[4])
                };
            }
            remainingQuery = remainingQuery.replace(numericRangeMatch[0], '').trim();
        }

        // Extract pattern seed: #661
        const patternMatch = remainingQuery.match(/#(\d+)/);
        if (patternMatch) {
            filter.pattern = patternMatch[1];
            remainingQuery = remainingQuery.replace(patternMatch[0], '').trim();
        }

        // Extract wear category: w:fn, w:mw, w:ft, w:ww, w:bs
        const wearMatch = remainingQuery.match(/w:([a-z]+)/i);
        if (wearMatch) {
            filter.wear = wearMatch[1].toLowerCase();
            remainingQuery = remainingQuery.replace(wearMatch[0], '').trim();
        }

        // Extract item category: cat:st, cat:sv, cat:norm
        const categoryMatch = remainingQuery.match(/cat:([a-z]+)/i);
        if (categoryMatch) {
            const catValue = categoryMatch[1].toLowerCase();
            if (catValue === 'st' || catValue === 'stattrak') {
                filter.category = 'stattrak';
            } else if (catValue === 'sv' || catValue === 'souvenir') {
                filter.category = 'souvenir';
            } else if (catValue === 'norm' || catValue === 'normal') {
                filter.category = 'normal';
            }
            remainingQuery = remainingQuery.replace(categoryMatch[0], '').trim();
        }

        // Extract collection: col:chroma 2
        const collectionMatch = remainingQuery.match(/col:([^\s]+(?:\s+\d+)?)/i);
        if (collectionMatch) {
            filter.collection = collectionMatch[1].toLowerCase();
            remainingQuery = remainingQuery.replace(collectionMatch[0], '').trim();
        }

        // Extract rarity: r:covert, r:milspec
        const rarityMatch = remainingQuery.match(/r:([a-z]+)/i);
        if (rarityMatch) {
            filter.rarity = rarityMatch[1].toLowerCase();
            remainingQuery = remainingQuery.replace(rarityMatch[0], '').trim();
        }

        // Extract storage box: box:A1, b:B1
        const storageMatch = remainingQuery.match(/(?:box|b):([^\s]+)/i);
        if (storageMatch) {
            filter.storageBox = storageMatch[1].toLowerCase();
            remainingQuery = remainingQuery.replace(storageMatch[0], '').trim();
        }

        // Remaining text is item name search
        if (remainingQuery.trim().length > 0) {
            filter.itemName = remainingQuery.trim();
        }

        return filter;
    }

    /**
     * Check if an item card matches the filter criteria
     * @param {HTMLElement} itemCard - The item card DOM element
     * @param {object} filterObj - The parsed filter object
     * @returns {boolean} True if item matches all filter criteria
     */
    function matchesFilter(itemCard, filterObj) {
        // If no filter criteria, show all items
        if (Object.keys(filterObj).length === 0) {
            return true;
        }

        // Item name search (includes name, category, and subcategory)
        if (filterObj.itemName) {
            const itemName = itemCard.dataset.itemName || '';
            const category = itemCard.dataset.category || '';
            const subcategory = itemCard.dataset.subcategory || '';

            // Check if the search term matches item name, category, or subcategory
            const matches = itemName.includes(filterObj.itemName) ||
                           category.includes(filterObj.itemName) ||
                           subcategory.includes(filterObj.itemName);

            if (!matches) {
                return false;
            }
        }

        // Float range filter
        if (filterObj.floatRange) {
            const floatValue = parseFloat(itemCard.dataset.float);
            if (isNaN(floatValue)) {
                return false; // Item has no float value
            }

            if (filterObj.floatRange.operator === '<') {
                if (floatValue >= filterObj.floatRange.value) {
                    return false;
                }
            } else if (filterObj.floatRange.operator === '>') {
                if (floatValue <= filterObj.floatRange.value) {
                    return false;
                }
            } else if (filterObj.floatRange.operator === 'range') {
                if (floatValue < filterObj.floatRange.min || floatValue > filterObj.floatRange.max) {
                    return false;
                }
            }
        }

        // Price range filter
        if (filterObj.priceRange) {
            const priceValue = parseFloat(itemCard.dataset.price);
            if (isNaN(priceValue)) {
                return false; // Item has no price
            }

            if (filterObj.priceRange.operator === '<') {
                if (priceValue >= filterObj.priceRange.value) {
                    return false;
                }
            } else if (filterObj.priceRange.operator === '>') {
                if (priceValue <= filterObj.priceRange.value) {
                    return false;
                }
            }
        }

        // Pattern seed filter
        if (filterObj.pattern) {
            const pattern = itemCard.dataset.pattern || '';
            if (pattern !== filterObj.pattern) {
                return false;
            }
        }

        // Wear category filter
        if (filterObj.wear) {
            const wear = itemCard.dataset.wear || '';
            if (!wear || !wear.includes(filterObj.wear)) {
                return false;
            }
        }

        // Item category filter
        if (filterObj.category) {
            if (filterObj.category === 'stattrak') {
                if (itemCard.dataset.isStattrak !== 'true') {
                    return false;
                }
            } else if (filterObj.category === 'souvenir') {
                if (itemCard.dataset.isSouvenir !== 'true') {
                    return false;
                }
            } else if (filterObj.category === 'normal') {
                if (itemCard.dataset.isNormal !== 'true') {
                    return false;
                }
            }
        }

        // Collection filter
        if (filterObj.collection) {
            const collection = itemCard.dataset.collection || '';
            if (!collection.includes(filterObj.collection)) {
                return false;
            }
        }

        // Rarity filter
        if (filterObj.rarity) {
            const rarity = itemCard.dataset.rarity || '';
            if (!rarity.includes(filterObj.rarity)) {
                return false;
            }
        }

        // Storage box filter
        if (filterObj.storageBox) {
            const storageBox = itemCard.dataset.storageBox || '';
            if (!storageBox.includes(filterObj.storageBox)) {
                return false;
            }
        }

        // All criteria passed
        return true;
    }

    /**
     * Apply the current filter to all items
     */
    function applyFilter() {
        const query = filterInput.value;
        const filterObj = parseFilterQuery(query);

        let visibleCount = 0;
        const totalCount = itemCards.length;

        // Filter each item card
        itemCards.forEach(card => {
            if (matchesFilter(card, filterObj)) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        // Update item count display
        if (query.trim() === '') {
            // No filter active - hide count
            itemCountDisplay.classList.add('hidden');
        } else {
            // Filter active - show count
            itemCountDisplay.classList.remove('hidden');
            visibleCountSpan.textContent = visibleCount;
            totalCountSpan.textContent = totalCount;
        }

        // Show/hide empty state
        if (visibleCount === 0 && query.trim() !== '') {
            emptyState.classList.remove('hidden');
            itemsGrid.classList.add('hidden');
        } else {
            emptyState.classList.add('hidden');
            itemsGrid.classList.remove('hidden');
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

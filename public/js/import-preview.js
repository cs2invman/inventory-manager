/**
 * Import Preview - Checkbox Selection Management
 * Handles checkbox interactions, bulk controls, filtering, and form submission
 */

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('import-form');
    const selectedItemsContainer = document.getElementById('selected-items-container');

    // Update counts when checkboxes change
    function updateCounts() {
        const addChecked = document.querySelectorAll('#items-to-add-grid input[type="checkbox"]:checked').length;
        const removeChecked = document.querySelectorAll('#items-to-remove-grid input[type="checkbox"]:checked').length;
        const storageBoxChecked = document.querySelectorAll('#storage-boxes-grid input[type="checkbox"]:checked').length;
        const totalSelected = addChecked + removeChecked + storageBoxChecked;

        const addCountEl = document.getElementById('items-to-add-count');
        const removeCountEl = document.getElementById('items-to-remove-count');
        const storageBoxCountEl = document.getElementById('storage-boxes-selected-count');
        const totalCountEl = document.getElementById('total-selected-count');

        if (addCountEl) addCountEl.textContent = addChecked;
        if (removeCountEl) removeCountEl.textContent = removeChecked;
        if (storageBoxCountEl) storageBoxCountEl.textContent = storageBoxChecked;
        if (totalCountEl) totalCountEl.textContent = totalSelected;
    }

    // Select all / deselect all for "Add" section
    const selectAllAdd = document.getElementById('select-all-add');
    const deselectAllAdd = document.getElementById('deselect-all-add');

    if (selectAllAdd) {
        selectAllAdd.addEventListener('click', () => {
            document.querySelectorAll('#items-to-add-grid input[type="checkbox"]').forEach(cb => {
                const card = cb.closest('.card');
                if (card && card.style.display !== 'none') {
                    cb.checked = true;
                }
            });
            updateCounts();
        });
    }

    if (deselectAllAdd) {
        deselectAllAdd.addEventListener('click', () => {
            document.querySelectorAll('#items-to-add-grid input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
            updateCounts();
        });
    }

    // Similar for "Remove" section
    const selectAllRemove = document.getElementById('select-all-remove');
    const deselectAllRemove = document.getElementById('deselect-all-remove');

    if (selectAllRemove) {
        selectAllRemove.addEventListener('click', () => {
            document.querySelectorAll('#items-to-remove-grid input[type="checkbox"]').forEach(cb => {
                const card = cb.closest('.card');
                if (card && card.style.display !== 'none') {
                    cb.checked = true;
                }
            });
            updateCounts();
        });
    }

    if (deselectAllRemove) {
        deselectAllRemove.addEventListener('click', () => {
            document.querySelectorAll('#items-to-remove-grid input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
            updateCounts();
        });
    }

    // Storage boxes selection controls
    const selectAllStorageBoxes = document.getElementById('select-all-storage-boxes');
    const deselectAllStorageBoxes = document.getElementById('deselect-all-storage-boxes');

    if (selectAllStorageBoxes) {
        selectAllStorageBoxes.addEventListener('click', () => {
            document.querySelectorAll('#storage-boxes-grid input[type="checkbox"]').forEach(cb => {
                cb.checked = true;
            });
            updateCounts();
        });
    }

    if (deselectAllStorageBoxes) {
        deselectAllStorageBoxes.addEventListener('click', () => {
            document.querySelectorAll('#storage-boxes-grid input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
            updateCounts();
        });
    }

    // Bulk select by filter
    document.querySelectorAll('[data-filter]').forEach(button => {
        button.addEventListener('click', function() {
            const filter = this.dataset.filter;
            const section = this.dataset.section; // 'add' or 'remove'
            const [type, value] = filter.split(':');

            const gridId = section === 'add' ? 'items-to-add-grid' : 'items-to-remove-grid';
            const grid = document.getElementById(gridId);

            if (!grid) return;

            grid.querySelectorAll('.card').forEach(card => {
                if (card.style.display === 'none') return; // Skip hidden items

                const checkbox = card.querySelector('input[type="checkbox"]');
                let matches = false;

                if (type === 'rarity') {
                    matches = card.dataset.rarity === value;
                } else if (type === 'type') {
                    matches = card.dataset.type === value;
                } else if (type === 'price') {
                    const operator = value.startsWith('>') ? '>' : '<';
                    const threshold = parseFloat(value.substring(1));
                    const price = parseFloat(card.dataset.price || '0');
                    matches = operator === '>' ? price > threshold : price < threshold;
                }

                if (matches && checkbox) {
                    checkbox.checked = true;
                }
            });

            updateCounts();
        });
    });

    // Search functionality for "Add" section
    const searchAdd = document.getElementById('search-add');
    if (searchAdd) {
        searchAdd.addEventListener('input', function() {
            const query = this.value.toLowerCase();
            document.querySelectorAll('#items-to-add-grid .card').forEach(card => {
                const nameElement = card.querySelector('h3');
                if (nameElement) {
                    const name = nameElement.textContent.toLowerCase();
                    card.style.display = name.includes(query) ? '' : 'none';
                }
            });
            // Don't update counts here - just hide/show cards
        });
    }

    // Search functionality for "Remove" section
    const searchRemove = document.getElementById('search-remove');
    if (searchRemove) {
        searchRemove.addEventListener('input', function() {
            const query = this.value.toLowerCase();
            document.querySelectorAll('#items-to-remove-grid .card').forEach(card => {
                const nameElement = card.querySelector('h3');
                if (nameElement) {
                    const name = nameElement.textContent.toLowerCase();
                    card.style.display = name.includes(query) ? '' : 'none';
                }
            });
        });
    }

    // Update counts on any checkbox change
    document.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        cb.addEventListener('change', updateCounts);
    });

    // Visual feedback for unchecked items (reduce opacity)
    document.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        // Set initial opacity based on checkbox state
        const card = cb.closest('.card');
        if (card) {
            card.style.opacity = cb.checked ? '1' : '0.5';
        }

        // Update opacity on change
        cb.addEventListener('change', function() {
            const card = this.closest('.card');
            if (card) {
                if (this.checked) {
                    card.style.opacity = '1';
                } else {
                    card.style.opacity = '0.5';
                }
            }
        });
    });

    // On form submit, collect selected IDs
    if (form) {
        form.addEventListener('submit', function(e) {
            // Check if any items are selected
            const totalSelected = document.querySelectorAll('input[type="checkbox"]:checked').length;
            if (totalSelected === 0) {
                e.preventDefault();
                alert('Please select at least one item to import.');
                return;
            }

            // Clear existing hidden inputs
            selectedItemsContainer.innerHTML = '';

            // Add selected item IDs as hidden inputs
            document.querySelectorAll('input[type="checkbox"]:checked').forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_items[]';
                input.value = cb.value;
                selectedItemsContainer.appendChild(input);
            });
        });
    }
});

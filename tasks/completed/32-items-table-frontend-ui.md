# Items Table - Frontend UI and Interactivity

**Status**: Completed
**Priority**: High
**Estimated Effort**: Large
**Created**: 2025-11-07
**Completed**: 2025-11-10

## Overview

Create the frontend UI for the Items table with server-side filtering, sorting, pagination, and interactive search. Uses Alpine.js for client-side state management and AJAX calls to backend API, styled with Tailwind CSS. Displays all 25,000+ CS2 marketplace items with advanced filtering capabilities.

## Problem Statement

Users need a way to browse and explore all CS2 marketplace items (not just their inventory) with:
- Real-time search that filters as they type
- Dropdown filters for category, type, rarity, subcategory
- Checkbox filters for StatTrak/Souvenir availability
- Sortable columns (click to sort ascending/descending)
- Pagination with page numbers and next/previous buttons
- Visual indicators for price trends (green for positive, red for negative)
- Loading states during AJAX calls
- Responsive design that works on mobile and desktop

## Requirements

### Functional Requirements
- **Search bar**: Text input at top of table, filters on name/marketName
  - Debounced (300ms delay after typing stops)
  - Clears when X button clicked
  - Shows loading spinner during search
- **Filter dropdowns**: Category, Subcategory, Type, Rarity
  - Populated from backend data (available filters)
  - "All" option to clear filter
  - Updates table when changed
- **Checkbox filters**: StatTrak Available, Souvenir Available
  - Updates table when toggled
- **Clear filters button**: Resets all filters to default
- **Sortable columns**: Click column header to sort
  - First click: ascending
  - Second click: descending
  - Visual indicator (arrow icon) shows current sort
- **Pagination**:
  - Shows current page, total pages, total items
  - Previous/Next buttons
  - Page number input (jump to page)
  - Items per page selector (25, 50, 100)
- **Table displays**:
  - Image (thumbnail)
  - Name (with quality badge if StatTrak/Souvenir)
  - Type badge (color-coded)
  - Category
  - Subcategory
  - Price (with currency symbol, formatted)
  - Volume (formatted with commas)
  - Last Updated (relative time, e.g., "2 days ago")
  - 7 Days Trend (percentage with color: green/red/gray)
  - 30 Days Trend (percentage with color: green/red/gray)
- **Loading states**: Skeleton loaders or spinner while fetching data
- **Empty state**: Message when no items match filters

### Non-Functional Requirements
- Responsive: Works on mobile (stack filters, horizontal scroll table)
- Accessible: Keyboard navigation, ARIA labels, screen reader support
- Performance: Smooth interactions, no lag during filtering/sorting
- Visual polish: Tailwind CSS styling consistent with existing pages

## Technical Approach

### Template Structure

Create: `templates/items/index.html.twig`

#### Overall Layout
```twig
{% extends 'base.html.twig' %}

{% block title %}CS2 Items Browser{% endblock %}

{% block body %}
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-cs2-orange">CS2 Items Browser</h1>
        <p class="text-gray-400 mt-2">Browse and explore all CS2 marketplace items with prices and trends</p>
    </div>

    <!-- Alpine.js Component -->
    <div x-data="itemsTable()" x-init="loadItems()">
        <!-- Filters Section -->
        <!-- Table Section -->
        <!-- Pagination Section -->
    </div>
</div>
{% endblock %}
```

#### Alpine.js Component (in template or separate JS file)

```javascript
function itemsTable() {
    return {
        // State
        items: [],
        loading: false,
        filters: {
            search: '',
            category: '',
            subcategory: '',
            type: '',
            rarity: '',
            stattrak: false,
            souvenir: false
        },
        availableFilters: {
            categories: [],
            subcategories: [],
            types: [],
            rarities: []
        },
        sortBy: 'name',
        sortDirection: 'asc',
        page: 1,
        perPage: 25,
        pagination: {
            total: 0,
            totalPages: 0,
            hasMore: false
        },
        searchDebounce: null,

        // Methods
        async loadItems() {
            this.loading = true;

            const params = new URLSearchParams({
                search: this.filters.search,
                category: this.filters.category,
                subcategory: this.filters.subcategory,
                type: this.filters.type,
                rarity: this.filters.rarity,
                stattrak: this.filters.stattrak ? '1' : '',
                souvenir: this.filters.souvenir ? '1' : '',
                sortBy: this.sortBy,
                sortDirection: this.sortDirection,
                page: this.page,
                perPage: this.perPage
            });

            // Remove empty params
            for (let [key, value] of Array.from(params.entries())) {
                if (!value) params.delete(key);
            }

            try {
                const response = await fetch(`/items/data?${params}`);
                const data = await response.json();

                if (data.error) {
                    console.error('Error loading items:', data.error);
                    // Show error message to user
                } else {
                    this.items = data.items;
                    this.pagination = data.pagination;
                    this.availableFilters = data.filters.available;
                }
            } catch (error) {
                console.error('Failed to fetch items:', error);
                // Show error message to user
            } finally {
                this.loading = false;
            }
        },

        onSearchInput() {
            clearTimeout(this.searchDebounce);
            this.searchDebounce = setTimeout(() => {
                this.page = 1; // Reset to page 1 on new search
                this.loadItems();
            }, 300);
        },

        onFilterChange() {
            this.page = 1; // Reset to page 1 on filter change
            this.loadItems();
        },

        clearFilters() {
            this.filters = {
                search: '',
                category: '',
                subcategory: '',
                type: '',
                rarity: '',
                stattrak: false,
                souvenir: false
            };
            this.page = 1;
            this.loadItems();
        },

        sortByColumn(column) {
            if (this.sortBy === column) {
                // Toggle direction
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                // New column, default to ascending
                this.sortBy = column;
                this.sortDirection = 'asc';
            }
            this.page = 1;
            this.loadItems();
        },

        goToPage(pageNum) {
            if (pageNum >= 1 && pageNum <= this.pagination.totalPages) {
                this.page = pageNum;
                this.loadItems();
            }
        },

        changePerPage(newPerPage) {
            this.perPage = parseInt(newPerPage);
            this.page = 1;
            this.loadItems();
        },

        formatPrice(price, currencySymbol) {
            if (price === null) return '--';
            return `${currencySymbol}${price.toFixed(2)}`;
        },

        formatVolume(volume) {
            if (volume === null) return '--';
            return volume.toLocaleString();
        },

        formatTrend(trend) {
            if (trend === null) return { text: '--', color: 'text-gray-400' };

            const sign = trend >= 0 ? '+' : '';
            const color = trend > 0 ? 'text-green-400' : trend < 0 ? 'text-red-400' : 'text-gray-400';

            return {
                text: `${sign}${trend.toFixed(1)}%`,
                color: color
            };
        },

        formatRelativeTime(isoDate) {
            if (!isoDate) return '--';

            const date = new Date(isoDate);
            const now = new Date();
            const diffMs = now - date;
            const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

            if (diffDays === 0) return 'Today';
            if (diffDays === 1) return 'Yesterday';
            if (diffDays < 7) return `${diffDays} days ago`;
            if (diffDays < 30) return `${Math.floor(diffDays / 7)} weeks ago`;
            return `${Math.floor(diffDays / 30)} months ago`;
        }
    };
}
```

### Filters Section UI

```twig
<!-- Search Bar -->
<div class="mb-6">
    <div class="relative">
        <input
            type="text"
            x-model="filters.search"
            @input="onSearchInput()"
            placeholder="Search items by name..."
            class="w-full px-4 py-3 bg-gray-800 text-white rounded-lg border border-gray-700 focus:border-cs2-orange focus:ring-1 focus:ring-cs2-orange"
        >
        <div class="absolute right-3 top-3">
            <svg x-show="loading" class="animate-spin h-5 w-5 text-cs2-orange" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <button x-show="!loading && filters.search" @click="filters.search = ''; onFilterChange()" class="text-gray-400 hover:text-white">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    </div>
</div>

<!-- Filter Dropdowns Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <!-- Category Filter -->
    <div>
        <label class="block text-sm font-medium text-gray-300 mb-2">Category</label>
        <select
            x-model="filters.category"
            @change="onFilterChange()"
            class="w-full px-3 py-2 bg-gray-800 text-white rounded-lg border border-gray-700 focus:border-cs2-orange"
        >
            <option value="">All Categories</option>
            <template x-for="cat in availableFilters.categories" :key="cat">
                <option :value="cat" x-text="cat"></option>
            </template>
        </select>
    </div>

    <!-- Subcategory Filter -->
    <div>
        <label class="block text-sm font-medium text-gray-300 mb-2">Subcategory</label>
        <select
            x-model="filters.subcategory"
            @change="onFilterChange()"
            class="w-full px-3 py-2 bg-gray-800 text-white rounded-lg border border-gray-700 focus:border-cs2-orange"
        >
            <option value="">All Subcategories</option>
            <template x-for="sub in availableFilters.subcategories" :key="sub">
                <option :value="sub" x-text="sub"></option>
            </template>
        </select>
    </div>

    <!-- Type Filter -->
    <div>
        <label class="block text-sm font-medium text-gray-300 mb-2">Type</label>
        <select
            x-model="filters.type"
            @change="onFilterChange()"
            class="w-full px-3 py-2 bg-gray-800 text-white rounded-lg border border-gray-700 focus:border-cs2-orange"
        >
            <option value="">All Types</option>
            <template x-for="type in availableFilters.types" :key="type">
                <option :value="type" x-text="type"></option>
            </template>
        </select>
    </div>

    <!-- Rarity Filter -->
    <div>
        <label class="block text-sm font-medium text-gray-300 mb-2">Rarity</label>
        <select
            x-model="filters.rarity"
            @change="onFilterChange()"
            class="w-full px-3 py-2 bg-gray-800 text-white rounded-lg border border-gray-700 focus:border-cs2-orange"
        >
            <option value="">All Rarities</option>
            <template x-for="rar in availableFilters.rarities" :key="rar">
                <option :value="rar" x-text="rar"></option>
            </template>
        </select>
    </div>

    <!-- Clear Filters Button -->
    <div class="flex items-end">
        <button
            @click="clearFilters()"
            class="w-full px-4 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-600 transition-colors"
        >
            Clear Filters
        </button>
    </div>
</div>

<!-- Checkbox Filters -->
<div class="flex flex-wrap gap-4 mb-6">
    <label class="flex items-center cursor-pointer">
        <input
            type="checkbox"
            x-model="filters.stattrak"
            @change="onFilterChange()"
            class="w-4 h-4 text-cs2-orange bg-gray-800 border-gray-600 rounded focus:ring-cs2-orange"
        >
        <span class="ml-2 text-sm text-gray-300">StatTrak™ Available</span>
    </label>

    <label class="flex items-center cursor-pointer">
        <input
            type="checkbox"
            x-model="filters.souvenir"
            @change="onFilterChange()"
            class="w-4 h-4 text-cs2-orange bg-gray-800 border-gray-600 rounded focus:ring-cs2-orange"
        >
        <span class="ml-2 text-sm text-gray-300">Souvenir Available</span>
    </label>
</div>
```

### Table Section UI

```twig
<!-- Table Container -->
<div class="bg-gray-800 rounded-lg shadow-steam overflow-hidden">
    <!-- Loading Overlay -->
    <div x-show="loading" class="absolute inset-0 bg-gray-900 bg-opacity-75 flex items-center justify-center z-10">
        <div class="text-center">
            <svg class="animate-spin h-10 w-10 text-cs2-orange mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <p class="text-gray-400">Loading items...</p>
        </div>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-900 border-b border-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Image</th>
                    <th
                        @click="sortByColumn('name')"
                        class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange"
                    >
                        <div class="flex items-center space-x-1">
                            <span>Name</span>
                            <svg x-show="sortBy === 'name'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                            </svg>
                        </div>
                    </th>
                    <th
                        @click="sortByColumn('type')"
                        class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange"
                    >
                        <div class="flex items-center space-x-1">
                            <span>Type</span>
                            <svg x-show="sortBy === 'type'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                            </svg>
                        </div>
                    </th>
                    <!-- Similar for other sortable columns: category, subcategory, price, volume, updatedAt, trend7d, trend30d -->
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-700">
                <!-- Empty State -->
                <tr x-show="!loading && items.length === 0">
                    <td colspan="10" class="px-4 py-12 text-center text-gray-400">
                        <svg class="h-12 w-12 mx-auto mb-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <p class="text-lg">No items found matching your filters</p>
                        <button @click="clearFilters()" class="mt-4 text-cs2-orange hover:text-orange-400">Clear all filters</button>
                    </td>
                </tr>

                <!-- Item Rows -->
                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-750 transition-colors">
                        <!-- Image -->
                        <td class="px-4 py-3">
                            <img :src="item.imageUrl" :alt="item.name" class="h-12 w-auto">
                        </td>

                        <!-- Name with Quality Badge -->
                        <td class="px-4 py-3">
                            <div class="flex items-center space-x-2">
                                <span class="font-medium text-white" x-text="item.name"></span>
                                <span x-show="item.stattrakAvailable" class="px-2 py-0.5 text-xs bg-orange-600 text-white rounded">ST</span>
                                <span x-show="item.souvenirAvailable" class="px-2 py-0.5 text-xs bg-yellow-600 text-white rounded">SV</span>
                            </div>
                        </td>

                        <!-- Type (simplified display) -->
                        <td class="px-4 py-3 text-sm text-gray-300" x-text="item.type"></td>

                        <!-- Category -->
                        <td class="px-4 py-3 text-sm text-gray-300" x-text="item.category"></td>

                        <!-- Subcategory -->
                        <td class="px-4 py-3 text-sm text-gray-300" x-text="item.subcategory || '--'"></td>

                        <!-- Price -->
                        <td class="px-4 py-3 text-sm font-medium text-white" x-text="formatPrice(item.price, item.currencySymbol)"></td>

                        <!-- Volume -->
                        <td class="px-4 py-3 text-sm text-gray-300" x-text="formatVolume(item.volume)"></td>

                        <!-- Last Updated -->
                        <td class="px-4 py-3 text-sm text-gray-400" x-text="formatRelativeTime(item.updatedAt)"></td>

                        <!-- 7 Days Trend -->
                        <td class="px-4 py-3 text-sm font-medium" :class="formatTrend(item.trend7d).color" x-text="formatTrend(item.trend7d).text"></td>

                        <!-- 30 Days Trend -->
                        <td class="px-4 py-3 text-sm font-medium" :class="formatTrend(item.trend30d).color" x-text="formatTrend(item.trend30d).text"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
```

### Pagination Section UI

```twig
<!-- Pagination -->
<div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4">
    <!-- Left: Items Info -->
    <div class="text-sm text-gray-400">
        Showing <span class="font-medium text-white" x-text="((page - 1) * perPage) + 1"></span>
        to <span class="font-medium text-white" x-text="Math.min(page * perPage, pagination.total)"></span>
        of <span class="font-medium text-white" x-text="pagination.total"></span> items
    </div>

    <!-- Center: Page Navigation -->
    <div class="flex items-center space-x-2">
        <!-- Previous Button -->
        <button
            @click="goToPage(page - 1)"
            :disabled="page === 1"
            :class="page === 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-700'"
            class="px-3 py-2 bg-gray-800 text-white rounded-lg border border-gray-700"
        >
            Previous
        </button>

        <!-- Page Numbers (show current page and neighbors) -->
        <template x-for="pageNum in [Math.max(1, page - 1), page, Math.min(pagination.totalPages, page + 1)]" :key="pageNum">
            <button
                @click="goToPage(pageNum)"
                :class="pageNum === page ? 'bg-cs2-orange text-white' : 'bg-gray-800 text-white hover:bg-gray-700'"
                class="px-3 py-2 rounded-lg border border-gray-700"
                x-text="pageNum"
            ></button>
        </template>

        <!-- Next Button -->
        <button
            @click="goToPage(page + 1)"
            :disabled="!pagination.hasMore"
            :class="!pagination.hasMore ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-700'"
            class="px-3 py-2 bg-gray-800 text-white rounded-lg border border-gray-700"
        >
            Next
        </button>
    </div>

    <!-- Right: Per Page Selector -->
    <div class="flex items-center space-x-2">
        <label class="text-sm text-gray-400">Per page:</label>
        <select
            x-model="perPage"
            @change="changePerPage($event.target.value)"
            class="px-3 py-2 bg-gray-800 text-white rounded-lg border border-gray-700 focus:border-cs2-orange"
        >
            <option value="25">25</option>
            <option value="50">50</option>
            <option value="100">100</option>
        </select>
    </div>
</div>
```

## Implementation Steps

1. **Create Template File**
   - Create `templates/items/index.html.twig`
   - Extend `base.html.twig`
   - Add page structure (header, filters, table, pagination)

2. **Implement Alpine.js Component**
   - Add `itemsTable()` function in `<script>` tag or separate JS file
   - Define all state variables
   - Implement `loadItems()` method with fetch API
   - Implement filter/sort/pagination methods
   - Add debouncing for search input (300ms)

3. **Build Filters UI**
   - Search bar with clear button and loading spinner
   - Dropdown filters for category, subcategory, type, rarity
   - Checkbox filters for StatTrak/Souvenir
   - Clear filters button
   - Wire up x-model bindings and @change handlers

4. **Build Table UI**
   - Table headers with sortable columns
   - Sort indicators (arrow icons)
   - Item rows with all columns
   - Image thumbnails
   - Quality badges (ST/SV)
   - Trend colors (green/red/gray)
   - Empty state message
   - Loading overlay

5. **Build Pagination UI**
   - Items count display
   - Previous/Next buttons
   - Page number buttons (current + neighbors)
   - Per page selector
   - Disable buttons when appropriate

6. **Styling with Tailwind**
   - Use consistent color scheme (gray-800/900 backgrounds, cs2-orange accents)
   - Hover states on interactive elements
   - Responsive design (stack filters on mobile, horizontal scroll table)
   - Loading spinner animations
   - Smooth transitions

7. **Test Interactivity**
   - Test search debouncing (should wait 300ms after typing)
   - Test filter combinations (multiple filters at once)
   - Test sorting (click headers, toggle direction)
   - Test pagination (next/prev, jump to page, per page)
   - Test clear filters button
   - Test loading states (should show during AJAX)
   - Test empty state (when no results)

8. **Accessibility**
   - Add ARIA labels to buttons and inputs
   - Ensure keyboard navigation works (tab through filters/buttons)
   - Screen reader announcements for loading/updates
   - Semantic HTML (proper table structure)

9. **Mobile Optimization**
   - Stack filters vertically on mobile
   - Horizontal scroll for table on mobile
   - Touch-friendly button sizes
   - Responsive pagination layout

## Edge Cases & Error Handling

- **AJAX fails**: Show error message, allow retry
- **No items match filters**: Show empty state with clear filters button
- **Invalid page number**: Backend handles, frontend displays results
- **Slow network**: Show loading state, don't allow multiple concurrent requests
- **User changes filter during load**: Cancel previous request (use AbortController)
- **Very long item names**: Truncate or wrap in table cell
- **Missing image URL**: Show placeholder image or fallback
- **Null price/volume/trend**: Display "--" instead of null
- **Invalid JSON response**: Catch error, show user-friendly message

## Dependencies

### Blocking Dependencies
- Task 31: Items table controller and AJAX endpoint (MUST BE COMPLETED FIRST)

### Related Tasks (same feature)
- Task 30: Items table backend repository and service layer (completed before Task 31)
- Task 31: Items table controller and AJAX endpoint (BLOCKING - must be completed first)

### Can Be Done in Parallel With
- None (this is the final task in the feature chain)

### External Dependencies
- Alpine.js (already loaded in base.html.twig)
- Tailwind CSS (already configured)
- Fetch API (native browser API)

## Acceptance Criteria

- [ ] Page renders with all filter UI elements
- [ ] Search input triggers debounced AJAX call (300ms)
- [ ] Dropdown filters update table when changed
- [ ] Checkbox filters update table when toggled
- [ ] Clear filters button resets all filters and reloads table
- [ ] Clicking column headers sorts table (ascending/descending toggle)
- [ ] Sort indicator (arrow) shows on active column
- [ ] Pagination shows correct page numbers and item counts
- [ ] Previous/Next buttons work and disable when appropriate
- [ ] Page number buttons work (click to jump to page)
- [ ] Per page selector changes number of items displayed
- [ ] Table displays all columns with correct data
- [ ] Item images load and display correctly
- [ ] Quality badges (ST/SV) show for appropriate items
- [ ] Price trends color-coded (green for positive, red for negative)
- [ ] Relative time displays correctly (e.g., "2 days ago")
- [ ] Loading spinner shows during AJAX calls
- [ ] Empty state message shows when no results
- [ ] Error message shows if AJAX fails
- [ ] Responsive on mobile (filters stack, table scrolls)
- [ ] Keyboard navigation works (tab through filters)
- [ ] Screen readers can navigate table
- [ ] No console errors or warnings
- [ ] Manual verification: Test all interactions in browser
- [ ] Manual verification: Test on mobile device or emulator

## Notes & Considerations

### Performance
- **Debouncing**: 300ms delay prevents excessive AJAX calls during typing
- **AbortController**: Cancel previous requests when new filter applied
- **Virtual scrolling**: Not needed for 25-100 items per page
- **Image lazy loading**: Consider `loading="lazy"` attribute for images

### UX Improvements
- **URL state**: Consider syncing filters/sort/page to URL query params (browser back/forward)
- **Saved filters**: Allow users to save common filter combinations
- **Column visibility**: Let users hide/show columns
- **Export**: Add "Export to CSV" button for filtered results
- **Item detail modal**: Click item row to show detailed view

### Future Enhancements
- Add "Compare" feature (select multiple items to compare)
- Add "Price alerts" (notify when item price changes)
- Add charts/graphs for price history
- Add "Similar items" recommendations
- Integrate with user inventory (show "You own this" badge)

### Known Limitations
- Search is simple text match (not fuzzy/typo-tolerant)
- Table not virtualized (may be slow with 100+ items per page)
- No keyboard shortcuts for common actions
- No column resizing or reordering

### Accessibility Notes
- Use `aria-label` on icon-only buttons
- Use `aria-live` region for loading status announcements
- Ensure sort indicators have text alternatives
- Use semantic `<table>` structure (thead, tbody, th, td)
- Test with screen reader (NVDA, JAWS, VoiceOver)

## Related Tasks

- Task 30: Items table backend repository and service layer (foundation)
- Task 31: Items table controller and AJAX endpoint (BLOCKING - must be completed first before starting this task)

/**
 * Relative Time Formatter
 * Displays timestamps in relative format (e.g., "5m ago", "2h ago", "3d ago")
 */

document.addEventListener('DOMContentLoaded', function() {
    /**
     * Format a timestamp as relative time
     * @param {string} isoTimestamp - ISO 8601 timestamp string
     * @returns {string} Formatted relative time string
     */
    function formatRelativeTime(isoTimestamp) {
        const now = new Date();
        const then = new Date(isoTimestamp);

        // Handle invalid dates
        if (isNaN(then.getTime())) {
            return 'Unknown';
        }

        const diffMs = now - then;
        const diffSeconds = Math.floor(diffMs / 1000);

        // Handle future timestamps (clock skew)
        if (diffSeconds < 0) {
            return '0m ago';
        }

        // Less than 60 seconds
        if (diffSeconds < 60) {
            return '0m ago';
        }

        // Less than 60 minutes - show minutes
        const diffMinutes = Math.floor(diffSeconds / 60);
        if (diffMinutes < 60) {
            return diffMinutes + 'm ago';
        }

        // Less than 24 hours - show hours
        const diffHours = Math.floor(diffMinutes / 60);
        if (diffHours < 24) {
            return diffHours + 'h ago';
        }

        // 24 hours or more - show days
        const diffDays = Math.floor(diffHours / 24);
        return diffDays + 'd ago';
    }

    /**
     * Update all relative time labels on the page
     */
    function updateRelativeTimeLabels() {
        const labels = document.querySelectorAll('.prices-updated-label');

        labels.forEach(function(label) {
            const container = label.closest('[data-timestamp]');
            if (container) {
                const timestamp = container.getAttribute('data-timestamp');
                if (timestamp) {
                    label.textContent = 'Last Updated: ' + formatRelativeTime(timestamp);
                }
            }
        });
    }

    // Update on page load
    updateRelativeTimeLabels();
});

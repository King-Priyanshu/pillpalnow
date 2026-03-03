jQuery(document).ready(function ($) {
    const $input = $('#drug_name_input');
    const $rxcuiInput = $('#rxcui_input');

    console.log('RxNorm Script Loaded. Input found:', $input.length);

    let timer = null;
    let selectedIndex = -1;
    const restUrl = (typeof pillpalnow_vars !== 'undefined' && pillpalnow_vars.rest_url) ? pillpalnow_vars.rest_url : '/wp-json/pillpalnow/v1/';

    if (typeof pillpalnow_vars === 'undefined') console.error('pillpalnow_vars is undefined!');

    if (!$input.length) {
        console.error('Drug name input not found! ID: drug_name_input');
        return;
    }

    // Create Dropdown & Spinner
    // Using DIVs to avoid any theme-specific UL/LI styling issues
    // Explicit inline styles for solid background
    const $dropdown = $('<div id="rxnorm-results" class="absolute z-[99999] shadow-2xl overflow-y-auto max-h-80 mt-1 rounded-md" style="display:none; background-color: #111827; border: 1px solid #374151;"></div>');
    $('body').append($dropdown);

    // Spinner (replaces search icon when loading)
    const $spinner = $(`
        <div class="absolute right-4 top-1/2 -translate-y-1/2 text-primary hidden" id="rx-loading">
            <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </div>
    `);

    // Helper to toggle spinner
    const $searchIcon = $input.siblings('div').find('svg'); // The search icon

    function toggleLoading(isLoading) {
        if (isLoading) {
            $searchIcon.parent().addClass('hidden');
            $spinner.removeClass('hidden');
        } else {
            $searchIcon.parent().removeClass('hidden');
            $spinner.addClass('hidden');
        }
    }

    $input.parent().append($spinner);

    function positionDropdown() {
        const offset = $input.offset();
        const height = $input.outerHeight();
        const width = $input.outerWidth();

        if (offset) {
            $dropdown.css({
                'top': (offset.top + height) + 'px',
                'left': offset.left + 'px',
                'width': width + 'px',
                'position': 'absolute',
                'z-index': 99999
            });
        }
    }

    $(window).on('resize scroll', positionDropdown);

    // Event Listener: Input
    $input.on('input', function () {
        const query = $(this).val().trim();

        clearTimeout(timer);
        selectedIndex = -1;
        $rxcuiInput.val(''); // Clear RxCUI on edit

        if (query.length < 3) {
            $dropdown.hide().empty();
            toggleLoading(false);
            return;
        }

        toggleLoading(true);

        timer = setTimeout(function () {

            $.ajax({
                url: restUrl + 'drug-search',
                method: 'GET',
                data: { keyword: query },
                success: function (response) {
                    $dropdown.empty();
                    toggleLoading(false);
                    console.log('RxNorm Response:', response);

                    if (response.success && response.data && response.data.length > 0) {
                        response.data.forEach((item, index) => {
                            // item is { label: "...", value: "..." }
                            // Using DIV with explicit styling for readability
                            // Added 'rx-item' class and inline style for hover behavior to be handled by JS or CSS if Tailwind fails
                            const $item = $(`<div class="rx-item p-4 cursor-pointer text-base text-gray-100 transition-colors border-b border-gray-700 leading-normal" data-index="${index}" style="padding: 1rem;">${escapeHtml(item.label)}</div>`);

                            // Manual hover handling for guaranteed result
                            $item.hover(
                                function () { $(this).css('background-color', '#374151'); }, // hover in (gray-700)
                                function () { $(this).css('background-color', 'transparent'); } // hover out
                            );

                            $item.on('click', function () {
                                selectDrug(item);
                            });

                            $dropdown.append($item);
                        });

                        // Show and Position
                        positionDropdown();
                        $dropdown.show();

                    } else {
                        $dropdown.append('<div class="p-4 text-base text-gray-400 italic">No results found</div>');
                        positionDropdown();
                        $dropdown.show();
                    }
                },
                error: function (xhr, status, error) {
                    toggleLoading(false);
                    console.error('RxNorm Error:', error, xhr.responseText);
                    $dropdown.empty().append('<div class="p-4 text-base text-red-400">Error fetching suggestions</div>');
                    positionDropdown();
                    $dropdown.show();
                }
            });

        }, 200); // 200ms debounce for faster typing feeling
    });

    // Function: Select Drug
    function selectDrug(item) {
        $input.val(item.label);
        $rxcuiInput.val(item.value);
        $dropdown.hide().empty();

        // Visual feedback success
        $input.addClass('border-green-500 focus:ring-green-500');
        setTimeout(() => $input.removeClass('border-green-500 focus:ring-green-500'), 1500);
    }

    // Keyboard Navigation
    $input.on('keydown', function (e) {
        // Updated selector for DIVs
        const $items = $dropdown.find('div:not(.text-gray-400)');
        if (!$items.length) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedIndex++;
            if (selectedIndex >= $items.length) selectedIndex = 0;
            highlightItem($items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedIndex--;
            if (selectedIndex < 0) selectedIndex = $items.length - 1;
            highlightItem($items);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (selectedIndex > -1 && $items[selectedIndex]) {
                $($items[selectedIndex]).click();
            }
        } else if (e.key === 'Escape') {
            $dropdown.hide();
        }
    });

    function highlightItem($items) {
        $items.removeClass('bg-gray-700 text-white'); // Clean up old selection
        const $current = $($items[selectedIndex]);
        $current.addClass('bg-gray-700 text-white'); // Highlight new selection

        // Scroll to view
        const offsetTop = $current.position().top + $dropdown.scrollTop();
        $dropdown.scrollTop(offsetTop);
    }

    // Blur Validation
    $input.on('blur', function () {
        // Delay to allow 'click' on dropdown to fire first
        setTimeout(() => {
            if ($input.val().length > 0 && !$rxcuiInput.val()) {
                $input.addClass('border-red-500').removeClass('focus:ring-primary');
                // Optional: show a small text help
                if (!$('#rx-error-msg').length) {
                    $('<p id="rx-error-msg" class="text-xs text-red-500 mt-1">Please select a valid option from the list.</p>').insertAfter($input.parent());
                }
            } else {
                $input.removeClass('border-red-500');
                $('#rx-error-msg').remove();
            }
        }, 200);
    });

    // Click Outside to Close
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.group').length) {
            $dropdown.hide();
        }
    });

    function escapeHtml(text) {
        if (!text) return text;
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
});

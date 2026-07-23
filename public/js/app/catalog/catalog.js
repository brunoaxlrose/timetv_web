// Executa somente apos todo o DOM e scripts (inclusive jQuery no rodape do layout) estarem carregados
document.addEventListener("DOMContentLoaded", function() {
    // Agora o jQuery ($) ja esta disponivel com total certeza

    window.openFilterModal = function() {
        $('#filterModal').addClass('active');
    };

    window.closeFilterModal = function() {
        $('#filterModal').removeClass('active');
    };

    window.openMediaTypeModal = function() {
        $('#mediaTypeModal').addClass('active');
    };

    window.closeMediaTypeModal = function() {
        $('#mediaTypeModal').removeClass('active');
    };

    window.toggleGroupedLayout = function() {
        const url = new URL(window.location.href);
        const currentlyGrouped = url.searchParams.get('grouped') !== '0';
        url.searchParams.set('grouped', currentlyGrouped ? '0' : '1');
        applyFiltersAjax(url.toString(), null, false);
    };

    window.resetFilters = function() {
        $('#statusFilterNone').prop('checked', true);
        $('#sortLastWatched').prop('checked', true);
        const $groupedSwitch = $('input[name="grouped"]');
        if ($groupedSwitch.length) $groupedSwitch.prop('checked', true);
    };

    /**
     * applyFiltersAjax
     * @param {string} url        - URL para requisição
     * @param {object|null} data  - Dados para enviar via POST (null = GET)
     * @param {boolean} updateUrl - Se deve atualizar a URL do browser (false para buscas)
     */
    function applyFiltersAjax(url, data, updateUrl) {
        const $results = $('#resultsGrid');
        const $skeleton = $('#skeletonContainer');
        const $itemCount = $('.d-flex.align-items-baseline.gap-2 span');

        // Close modals
        closeFilterModal();
        closeMediaTypeModal();

        // Show loading skeleton
        if ($results.length && $skeleton.length) {
            $results.addClass('d-none');
            $skeleton.removeClass('d-none');
        }
        showGlobalLoader(true);

        const ajaxOptions = {
            url: url,
            success: function(responseData) {
                const html = $.parseHTML(responseData);
                const $newGrid = $(html).find('#resultsGrid');
                const $newCount = $(html).find('.d-flex.align-items-baseline.gap-2 span');

                if ($newGrid.length) {
                    $('#resultsGrid').html($newGrid.html());
                }
                if ($newCount.length) {
                    $itemCount.text($newCount.text());
                }

                // Only update browser URL for filters (not search)
                if (updateUrl !== false) {
                    history.pushState(null, '', url);
                }
            },
            error: function() {
                showToast('Erro ao carregar os itens.', false);
            },
            complete: function() {
                if ($results.length && $skeleton.length) {
                    $results.removeClass('d-none');
                    $skeleton.addClass('d-none');
                }
                showGlobalLoader(false);
            }
        };

        if (data !== null && data !== undefined) {
            // Send as POST with data body (search - URL stays clean)
            ajaxOptions.type = 'POST';
            ajaxOptions.data = data;
        } else {
            // Send as GET with query string (filters - URL updates)
            ajaxOptions.type = 'GET';
        }

        $.ajax(ajaxOptions);
    }

    // Click outside modals to close
    $(document).on('click', function(e) {
        const filterModal = document.getElementById('filterModal');
        const mediaTypeModal = document.getElementById('mediaTypeModal');

        if (e.target === filterModal) closeFilterModal();
        if (e.target === mediaTypeModal) closeMediaTypeModal();
    });

    // --- Search: send via $.ajax POST - URL stays /catalog ---
    function doSearch() {
        const val = $('#searchInput').val().trim();
        applyFiltersAjax('/catalog', { search: val }, false);
    }

    // Live search: trigger 2000ms after last keystroke if query length >= 3
    let searchDebounce;
    $('#searchInput').on('input', function() {
        clearTimeout(searchDebounce);
        const val = $(this).val().trim();
        
        // Só pesquisa se tiver pelo menos 3 letras ou estiver vazio (para resetar a busca)
        if (val.length === 0 || val.length >= 3) {
            searchDebounce = setTimeout(doSearch, 2000);
        }
    });

    // Also trigger on Enter key immediately (ignoring the 2s debounce but requiring 3 chars)
    $('#searchInput').on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(searchDebounce);
            const val = $(this).val().trim();
            if (val.length === 0 || val.length >= 3) {
                doSearch();
            }
        }
    });

    // --- Filters & Media Type: send via GET, URL updates ---
    $('#filterForm, #mediaTypeForm').on('submit', function(e) {
        e.preventDefault();
        const form = $(this);
        const action = form.attr('action') || '/catalog';
        const params = form.serialize();
        const fullUrl = action + '?' + params;
        applyFiltersAjax(fullUrl, null, true);
    });

    // Handle back/forward history navigation
    window.onpopstate = function() {
        applyFiltersAjax(window.location.href, null, false);
    };
});



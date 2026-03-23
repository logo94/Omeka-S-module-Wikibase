(function ($) {

    function getCurrentLang() {
        var lang = $('html').attr('lang') || wikibaseConfig.languages[0];
        return lang.split('_')[0].split('-')[0];
    }

    // Crea un elemento li per il dropdown
    function createDropdownItem(item, onSelect) {
        var $li = $('<li>').css({
            padding:      '8px 10px',
            cursor:       'pointer',
            borderBottom: '1px solid #eee',
            fontSize:     '13px',
            lineHeight:   '1.4',
        }).html(
            '<strong>' + $('<span>').text(item.label).html() + '</strong>' +
            (item.description
                ? '<br><small style="color:#666">' + $('<span>').text(item.description).html() + '</small>'
                : '')
        );

        $li.on('mouseenter', function () {
            $(this).css('background', '#f5f5f5');
        }).on('mouseleave', function () {
            $(this).css('background', '#fff');
        }).on('mousedown', function (e) {
            e.preventDefault();
            onSelect(item);
        });

        return $li;
    }

    // Popola il dropdown con i risultati
    function renderDropdown($dropdown, results, onSelect) {
        $dropdown.empty();

        if (!results || results.length === 0) {
            $dropdown.append($('<li>', {
                text:  'Nessun risultato',
                style: 'padding:8px 10px; color:#999; font-style:italic;',
            }));
            $dropdown.show();
            return;
        }

        $.each(results, function (i, item) {
            $dropdown.append(createDropdownItem(item, onSelect));
        });

        $dropdown.show();
    }

    // Cerca entità nel proxy
    function searchWikibase(query, lang, term, onSuccess, onError) {
        $.ajax({
            url:      wikibaseConfig.proxyUrl,
            dataType: 'json',
            data: {
                query:    query,
                lang:     lang,
                property: term,
            },
            success: onSuccess,
            error:   onError || function (xhr) {
                console.error('Wikibase proxy error:', xhr.status, xhr.responseText);
            },
        });
    }

    function initSuggestOnValue($value) {
        if ($value.hasClass('wikibase-init')) {
            return;
        }

        var term = $value.closest('.resource-property').data('property-term');
        if (!term || !wikibaseConfig.mapping[term]) {
            return;
        }

        var propConfig = wikibaseConfig.mapping[term];
        var lang       = getCurrentLang();

        var $uriInput   = $value.find('input.uri-value[data-value-key="@id"]');
        var $labelInput = $value.find('textarea.value-label[data-value-key="o:label"]');
        var $langInput  = $value.find('input.value-language[data-value-key="o:lang"]');

        if (!$uriInput.length) {
            return;
        }

        $value.addClass('wikibase-init');

        $uriInput.closest('.input-body').css('position', 'relative');

        var $searchBox = $('<input>', {
            type:        'text',
            placeholder: propConfig.label || (term.split(':')[1] || 'Cerca in Wikibase...'),
            class:       'wikibase-search-input',
            style:       'width:100%; margin-bottom:6px; padding:5px 8px; border:1px solid #ccc; border-radius:3px; box-sizing:border-box; font-size:13px;',
        });

        var $dropdown = $('<ul>', {
            class: 'wikibase-dropdown',
            style: [
                'display:none',
                'position:absolute',
                'z-index:99999',
                'background:#fff',
                'border:1px solid #ccc',
                'border-radius:3px',
                'list-style:none',
                'margin:0',
                'padding:0',
                'max-height:250px',
                'overflow-y:auto',
                'width:100%',
                'box-shadow:0 4px 12px rgba(0,0,0,0.15)',
                'left:0',
            ].join(';'),
        });

        $uriInput.closest('.input').before(
            $('<div>', { style: 'position:relative; margin-bottom:4px;' })
                .append($searchBox)
                .append($dropdown)
        );

        if ($uriInput.val() && $labelInput.val()) {
            $searchBox.val($labelInput.val());
        }

        // Gestisce la selezione di un candidato — popola URI e label per tutte le lingue
        function handleSelection(selectedItem) {
            var $property = $value.closest('.resource-property');

            $.ajax({
                url:      wikibaseConfig.labelsUrl,
                dataType: 'json',
                data:     { id: selectedItem.id },
                success: function (data) {
                    var labels      = data.labels || {};
                    var langsToSave = wikibaseConfig.languages.slice();
                    var currentIdx  = langsToSave.indexOf(lang);

                    // Lingua corrente sempre prima
                    if (currentIdx > 0) {
                        langsToSave.splice(currentIdx, 1);
                        langsToSave.unshift(lang);
                    }

                    // Popola il primo valore esistente
                    var firstLabel = labels[langsToSave[0]] || selectedItem.label;
                    $uriInput.val(selectedItem.value).trigger('change');
                    $labelInput.val(firstLabel).trigger('change');
                    $langInput.val(langsToSave[0]).trigger('change');
                    $searchBox.val(firstLabel);

                    // Aggiungi ricorsivamente i valori per le lingue rimanenti
                    var remainingLangs = langsToSave.slice(1);
                    if (remainingLangs.length === 0) {
                        $dropdown.hide().empty();
                        return;
                    }

                    function addNextLang(index) {
                        if (index >= remainingLangs.length) {
                            $dropdown.hide().empty();
                            return;
                        }

                        var langToAdd  = remainingLangs[index];
                        var labelToAdd = labels[langToAdd] || selectedItem.label;

                        var $addBtn = $property.find('.add-value[data-type="uri"]').first();
                        if (!$addBtn.length) {
                            $addBtn = $property.find('.add-values.single-selector .add-value').first();
                        }

                        if ($addBtn.length) {
                            $addBtn.trigger('click');
                            setTimeout(function () {
                                var $newValue = $property
                                    .find('.value[data-data-type="uri"]')
                                    .not('.wikibase-init')
                                    .last();

                                if ($newValue.length) {
                                    $newValue.find('input.uri-value').val(selectedItem.value).trigger('change');
                                    $newValue.find('textarea.value-label').val(labelToAdd).trigger('change');
                                    $newValue.find('input.value-language').val(langToAdd).trigger('change');
                                    $newValue.addClass('wikibase-init');
                                }

                                addNextLang(index + 1);
                            }, 150);
                        } else {
                            addNextLang(index + 1);
                        }
                    }

                    addNextLang(0);
                },
                error: function () {
                    // Fallback senza multilingua
                    $uriInput.val(selectedItem.value).trigger('change');
                    $labelInput.val(selectedItem.label).trigger('change');
                    $searchBox.val(selectedItem.label);
                    $dropdown.hide().empty();
                },
            });
        }

        var searchTimeout;

        $searchBox.on('keyup', function () {
            var query = $.trim($(this).val());
            clearTimeout(searchTimeout);

            if (query.length < 2) {
                $dropdown.hide().empty();
                return;
            }

            searchTimeout = setTimeout(function () {
                searchWikibase(query, lang, term, function (data) {
                    renderDropdown($dropdown, data.results, handleSelection);
                });
            }, 300);
        });

        $searchBox.on('focus', function () {
            var query = $.trim($(this).val());

            // Preload al focus se configurato e campo vuoto
            if (query.length === 0 && propConfig.preload) {
                searchWikibase('a', lang, term, function (data) {
                    renderDropdown($dropdown, data.results, handleSelection);
                });
                return;
            }

            if (query.length >= 2 && $dropdown.children().length > 0) {
                $dropdown.show();
            }
        });

        $searchBox.on('blur', function () {
            setTimeout(function () {
                $dropdown.hide();
            }, 200);
        });
    }

    function initAll() {
        $.each(wikibaseConfig.mapping, function (term) {
            $('.resource-property[data-property-term="' + term + '"]')
                .find('.value[data-data-type="uri"]')
                .not('.wikibase-init')
                .each(function () {
                    initSuggestOnValue($(this));
                });
        });
    }

    function waitForProperties(callback) {
        if ($('.resource-property[data-property-term]').length > 0) {
            setTimeout(callback, 500);
            return;
        }
        var observer = new MutationObserver(function (mutations, obs) {
            if ($('.resource-property[data-property-term]').length > 0) {
                obs.disconnect();
                setTimeout(callback, 500);
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    $(document).ready(function () {
        waitForProperties(initAll);

        $(document).on('o:value-created', function (e, value) {
            if (value && value.valueObj && value.valueObj.$el) {
                initSuggestOnValue(value.valueObj.$el);
            }
        });
    });

}(jQuery));
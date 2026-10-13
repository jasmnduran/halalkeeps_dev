(function () {
    'use strict';

    var state = null;
    var pendingSuggestions = [];

    function byId(id) {
        return document.getElementById(id);
    }

    function escapeHtml(value) {
        var node = document.createElement('span');
        node.textContent = String(value == null ? '' : value);
        return node.innerHTML;
    }

    function displayStatus(status) {
        return String(status || '').replace(/_/g, ' ');
    }

    function showNotice(message, isError) {
        var notice = byId('aiMappingNotice');
        if (!notice) return;
        notice.textContent = message;
        notice.style.display = 'block';
        notice.style.background = isError ? '#fef2f2' : '#ecfdf5';
        notice.style.color = isError ? '#991b1b' : '#166534';
        notice.style.border = isError ? '1px solid #fecaca' : '1px solid #bbf7d0';
    }

    async function request(action, values) {
        var body = new FormData();
        body.append('action', action);
        Object.keys(values || {}).forEach(function (key) {
            body.append(key, values[key]);
        });

        var response;
        try {
            response = await fetch(window.ingredientMenuApi, { method: 'POST', body: body });
        } catch (_) {
            throw new Error('Network error while contacting the ingredient mapping service.');
        }

        var payload;
        try {
            payload = await response.json();
        } catch (_) {
            throw new Error('The ingredient mapping service returned an invalid response.');
        }
        if (!payload.success) {
            throw new Error(payload.message || 'The ingredient mapping request could not be completed.');
        }
        return payload;
    }

    function candidateName(id) {
        var selector = byId('aiIngredientInventoryId');
        if (!selector) return 'Inventory item #' + id;
        for (var index = 0; index < selector.options.length; index += 1) {
            if (String(selector.options[index].value) === String(id)) {
                return selector.options[index].textContent.trim();
            }
        }
        return 'Inventory item #' + id;
    }

    function hideSuggestions() {
        pendingSuggestions = [];
        var target = byId('aiMappingSuggestions');
        if (target) {
            target.style.display = 'none';
            target.innerHTML = '';
        }
    }

    function renderPublishControls() {
        var target = byId('aiRecipePublishControls');
        if (!target || !state || !state.recipe) return;
        var rows = state.ingredients || [];
        var confirmed = rows.filter(function (row) {
            return row.mapping_status === 'confirmed' && Number(row.inventory_id || 0) > 0;
        }).length;
        var total = rows.length;
        var status = state.recipe.customer_visibility_status || 'draft';
        var reviewed = rows.filter(function (row) { return row.mapping_status === 'confirmed' || row.mapping_status === 'rejected'; }).length;
        var complete = total > 0 && reviewed === total && confirmed > 0;
        var statusText = status === 'published'
            ? 'Published to customers (confirmed ingredients only).'
            : (status === 'needs_reapproval' ? 'Needs Chef re-approval after a recipe or mapping change.' : 'Not published to customers yet.');
        var statusColor = status === 'published' ? '#166534' : (status === 'needs_reapproval' ? '#92400e' : '#475569');
        var button = '';
        if (status !== 'published') {
            button = '<button type="button" class="btn btn-primary btn-sm" onclick="publishAIMappingRecipe()"' +
                (complete ? '' : ' disabled') + ' style="white-space:nowrap;">' +
                '<i class="fas fa-cloud-arrow-up"></i> Approve recipe for customer listing</button>';
        }
        var hint = complete
            ? 'Every ingredient has been reviewed. Only the ' + confirmed + ' confirmed mapping(s) will be visible to customers; rejected items stay hidden.'
            : 'Review every ingredient before approving the listing (' + confirmed + '/' + total + ' confirmed; unresolved suggestions remain).';
        target.innerHTML = '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;">' +
            '<div><div style="font-size:.78rem;font-weight:800;color:' + statusColor + ';">Customer ingredient listing: ' + escapeHtml(statusText) + '</div>' +
            '<div style="font-size:.74rem;color:#64748b;margin-top:4px;">' + escapeHtml(hint) + '</div></div>' + button + '</div>';
    }

    function renderRecipe() {
        if (!state || !state.recipe) return;

        byId('aiRecipeTitle').textContent = state.recipe.recipe_name;
        byId('aiRecipeMeta').textContent =
            state.menu.name + ' - ' + (state.recipe.serving_size || 'Serving size not set') +
            ' - Version ' + state.recipe.version_no;

        var target = byId('aiRecipeIngredientList');
        var rows = state.ingredients || [];
        if (!rows.length) {
            target.innerHTML = '<span style="color:#64748b;">No ingredients recorded yet.</span>';
            renderPublishControls();
            return;
        }

        var body = rows.map(function (row) {
            var color = row.mapping_status === 'confirmed' ? '#166534' :
                (row.mapping_status === 'suggested' ? '#92400e' :
                    (row.mapping_status === 'rejected' ? '#991b1b' : '#475569'));
            var inventoryName = row.mapping_status === 'confirmed'
                ? row.confirmed_inventory_name
                : (row.suggested_inventory_name || 'No confirmed inventory mapping');
            return '<tr style="border-top:1px solid #f1f5f9;">' +
                '<td style="padding:9px 7px;font-weight:700;color:#334155;">' + escapeHtml(row.ingredient_name) + '</td>' +
                '<td style="padding:9px 7px;white-space:nowrap;">' + escapeHtml(row.quantity_required) + ' ' + escapeHtml(row.unit) + '</td>' +
                '<td style="padding:9px 7px;"><span style="font-size:.71rem;font-weight:700;color:' + color + ';">' +
                escapeHtml(displayStatus(row.mapping_status)) + '</span><div style="font-size:.75rem;color:#64748b;margin-top:2px;">' +
                escapeHtml(inventoryName) + '</div></td></tr>';
        }).join('');

        target.innerHTML = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">' +
            '<thead><tr style="color:#64748b;font-size:.7rem;text-transform:uppercase;">' +
            '<th style="padding:7px;text-align:left;">Recipe ingredient</th>' +
            '<th style="padding:7px;text-align:left;">Requirement</th>' +
            '<th style="padding:7px;text-align:left;">Mapping</th></tr></thead><tbody>' +
            body + '</tbody></table></div>';
        renderPublishControls();
    }
    function renderSuggestions(data) {
        var target = byId('aiMappingSuggestions');
        var suggestions = data.suggestions || [];
        target.style.display = 'block';

        if (!suggestions.length) {
            target.innerHTML = '<strong style="color:#4c1d95;">All generated ingredient suggestions have been reviewed by the Chef.</strong>';
            return;
        }

        var content = suggestions.map(function (suggestion) {
            var candidateId = Number(suggestion.suggested_inventory_ingredient_id || 0);
            var candidate = candidateId > 0 ? candidateName(candidateId) : 'No reliable inventory match';
            var warning = suggestion.potential_substitution_warning
                ? '<div style="font-size:.75rem;color:#9a3412;margin-top:5px;"><i class="fas fa-triangle-exclamation"></i> ' +
                    escapeHtml(suggestion.potential_substitution_warning) + '</div>'
                : '';
            var buttons;
            if (candidateId > 0) {
                buttons = '<div style="display:flex;gap:7px;margin-top:9px;">' +
                    '<button type="button" class="btn btn-primary btn-sm" onclick="reviewAIMapping(' +
                    Number(suggestion.recipe_ingredient_id) + ',' + candidateId + ',\'confirm\')"><i class="fas fa-check"></i> Chef confirms</button>' +
                    '<button type="button" class="btn btn-outline btn-sm" onclick="reviewAIMapping(' +
                    Number(suggestion.recipe_ingredient_id) + ',0,\'reject\')"><i class="fas fa-times"></i> Reject</button></div>';
            } else {
                buttons = '<div style="margin-top:8px;"><button type="button" class="btn btn-outline btn-sm" onclick="reviewAIMapping(' +
                    Number(suggestion.recipe_ingredient_id) + ',0,\'reject\')"><i class="fas fa-times"></i> Mark unmatched / reject</button></div>';
            }
            return '<div style="padding:12px;border:1px solid #ddd6fe;border-radius:9px;background:#fff;margin-top:9px;">' +
                '<div style="font-weight:800;color:#312e81;">' + escapeHtml(candidate) + '</div>' +
                '<div style="font-size:.76rem;color:#64748b;margin-top:3px;"><strong>' +
                escapeHtml(displayStatus(suggestion.match_category)) + '</strong> · confidence indicator: ' +
                escapeHtml(suggestion.confidence_indicator) + '</div>' +
                '<div style="font-size:.78rem;color:#475569;margin-top:5px;">' +
                escapeHtml(suggestion.explanation) + '</div>' + warning + buttons + '</div>';
        }).join('');

        target.innerHTML = '<div style="font-weight:800;color:#4c1d95;margin-bottom:6px;">3. Chef review of suggestions</div>' +
            '<p style="margin:0 0 12px;font-size:.78rem;color:#6b21a8;">A suggestion is not a substitution approval. Confirm only after checking ingredient type, cut, unit, batch, and recipe requirement.</p>' +
            content;
    }

    window.openAIMappingModal = function () {
        var modal = byId('aiRecipeMappingModal');
        if (!modal) return;
        modal.style.display = 'block';
        showNotice('Select a menu item, then load or create its structured recipe.');
    };

    window.closeAIMappingModal = function () {
        var modal = byId('aiRecipeMappingModal');
        if (modal) modal.style.display = 'none';
    };

    window.loadAIMappingRecipe = async function (preserveSuggestions) {
        var menuId = byId('aiRecipeMenuId').value;
        if (!menuId) {
            showNotice('Select a menu item first.', true);
            return;
        }

        try {
            var data = await request('recipe_details', { menu_item_id: menuId });
            if (!preserveSuggestions) hideSuggestions();

            if (!data.recipe) {
                state = null;
                byId('aiRecipeCreatePanel').style.display = 'block';
                byId('aiRecipeWorkspace').style.display = 'none';
                var selected = byId('aiRecipeMenuId').options[byId('aiRecipeMenuId').selectedIndex];
                byId('aiRecipeName').value = (selected ? selected.textContent.trim() : 'Menu item') + ' recipe';
                byId('aiRecipeServing').value = '1 serving';
                showNotice('No structured recipe exists yet. Create one, then add its ingredients.');
                return;
            }

            state = data;
            byId('aiRecipeCreatePanel').style.display = 'none';
            byId('aiRecipeWorkspace').style.display = 'block';
            renderRecipe();
            showNotice('Structured recipe loaded. Add unmatched ingredient names, then generate suggestions.');
        } catch (error) {
            showNotice(error.message, true);
        }
    };

    window.publishAIMappingRecipe = async function () {
        var recipeId = state && state.recipe ? state.recipe.id : null;
        if (!recipeId) {
            showNotice('Load a structured recipe first.', true);
            return;
        }
        try {
            await request('approve_recipe_listing', { recipe_id: recipeId });
            pendingSuggestions = [];
            await window.loadAIMappingRecipe(true);
            showNotice('Recipe ingredient listing approved by the Chef. Confirmed ingredients are now visible to customers.');
        } catch (error) {
            showNotice(error.message, true);
        }
    };
    window.createAIMappingRecipe = async function () {
        var menuId = byId('aiRecipeMenuId').value;
        var recipeName = byId('aiRecipeName').value.trim();
        var servingSize = byId('aiRecipeServing').value.trim();
        if (!menuId || !recipeName) {
            showNotice('Select a menu item and enter a recipe name.', true);
            return;
        }
        try {
            await request('save_recipe', {
                menu_item_id: menuId,
                recipe_name: recipeName,
                serving_size: servingSize
            });
            showNotice('Structured recipe created. Add a human-readable ingredient name to request a mapping.');
            await window.loadAIMappingRecipe(false);
        } catch (error) {
            showNotice(error.message, true);
        }
    };

    window.addAIMappingIngredient = async function () {
        var recipeId = state && state.recipe ? state.recipe.id : null;
        var name = byId('aiIngredientName').value.trim();
        var quantity = byId('aiIngredientQuantity').value;
        var unit = byId('aiIngredientUnit').value;
        var inventoryId = byId('aiIngredientInventoryId').value || '';
        if (!recipeId || !name || !quantity) {
            showNotice('Enter an ingredient name and a positive quantity.', true);
            return;
        }
        try {
            await request('save_recipe_ingredient', {
                recipe_id: recipeId,
                ingredient_name: name,
                quantity_required: quantity,
                unit: unit,
                yield_factor: '1.000',
                inventory_id: inventoryId
            });
            byId('aiIngredientName').value = '';
            byId('aiIngredientQuantity').value = '';
            byId('aiIngredientInventoryId').value = '';
            showNotice(inventoryId ? 'Ingredient manually confirmed by the Chef.' : 'Ingredient added as unmatched. Generate a mapping suggestion next.');
            await window.loadAIMappingRecipe(false);
        } catch (error) {
            showNotice(error.message, true);
        }
    };

    window.generateAIMappings = async function () {
        var recipeId = state && state.recipe ? state.recipe.id : null;
        if (!recipeId) {
            showNotice('Load a structured recipe first.', true);
            return;
        }
        var button = byId('aiSuggestButton');
        var original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating…';
        try {
            var data = await request('suggest_mappings', { recipe_id: recipeId });
            pendingSuggestions = data.suggestions || [];
            await window.loadAIMappingRecipe(true);
            renderSuggestions({ suggestions: pendingSuggestions });
            if (data.ai_available) {
                showNotice('AI suggestions generated using ' + data.provider + (data.model ? ' (' + data.model + ')' : '') + '. Chef confirmation is still required.');
            } else {
                showNotice('No configured AI provider was available; deterministic matching and manual review remain available.', true);
            }
        } catch (error) {
            showNotice(error.message, true);
        } finally {
            button.disabled = false;
            button.innerHTML = original;
        }
    };

    window.reviewAIMapping = async function (recipeIngredientId, inventoryId, decision) {
        try {
            await request('confirm_mapping', {
                recipe_ingredient_id: recipeIngredientId,
                inventory_id: inventoryId,
                decision: decision
            });
            pendingSuggestions = pendingSuggestions.filter(function (suggestion) {
                return Number(suggestion.recipe_ingredient_id) !== Number(recipeIngredientId);
            });
            await window.loadAIMappingRecipe(true);
            renderSuggestions({ suggestions: pendingSuggestions });
            showNotice(decision === 'confirm'
                ? 'Chef confirmation saved. Review the remaining suggestions before closing this panel.'
                : 'Suggestion rejected. Review the remaining suggestions before closing this panel.');
        } catch (error) {
            showNotice(error.message, true);
        }
    };

    document.addEventListener('click', function (event) {
        var modal = byId('aiRecipeMappingModal');
        if (modal && event.target === modal) window.closeAIMappingModal();
    });
}());

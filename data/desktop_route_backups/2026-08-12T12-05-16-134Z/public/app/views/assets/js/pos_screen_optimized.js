async function handleAjaxSession(response) {
    const text = await response.text();

    if (response.status === 401) {
        window.location.href = '/onerp/app/public/app/views/login_view.php';
        return null;
    }

    try {
        const data = JSON.parse(text);
        if (data && data.status === 'expired' && data.redirect) {
            window.location.href = data.redirect;
            return null;
        }
    } catch (e) {
        // Not JSON, normal cart HTML response.
    }

    if (text.includes('/onerp/app/public/app/views/login_view.php') || text.trim() === 'Login required') {
        window.location.href = '/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return text;
}

async function handleAjaxJsonSession(response) {
    const text = await response.text();

    if (response.status === 401) {
        try {
            const data = JSON.parse(text);
            window.location.href = data.redirect || '/onerp/app/public/app/views/login_view.php';
        } catch (e) {
            window.location.href = '/onerp/app/public/app/views/login_view.php';
        }
        return null;
    }

    try {
        const data = JSON.parse(text);

        if (data && data.status === 'expired' && data.redirect) {
            window.location.href = data.redirect;
            return null;
        }

        return data;
    } catch (e) {
        throw new Error('Invalid JSON response: ' + text);
    }
}
    
const POS_CONFIG = window.ONEERP_POS_CONFIG || {};
const customers = POS_CONFIG.customers || {Retailer: [], Wholesaler: []};
const productTaxMap = POS_CONFIG.productTaxMap || {};
const defaultProductTaxRate = Number(POS_CONFIG.defaultProductTaxRate || 0);
const hasWholesaleCustomer = Boolean(POS_CONFIG.hasWholesaleCustomer);
const PHARMACY_UNIT_ENABLED = Boolean(POS_CONFIG.pharmacyUnitEnabled);
const PRODUCT_UNIT_MAP = POS_CONFIG.productUnitMap || {};

function isWholesaleMode(type) {
    return type === 'Wholesale1' || type === 'Wholesale2' || type === 'Wholesale3';
}

function guardWholesaleMode(type) {
    if (isWholesaleMode(type) && !hasWholesaleCustomer) {
        alert('Wholesale function is not available for this client.');
        return false;
    }
    return true;
}

let selectedType = 'Retailer';
let selectedCustomerId = '';
let selectedCustomerName = 'Retailer / Walk-In';
let selectedCustomerPhone = '';
let selectedPaymentMethod = 'cash';
let retailerCustomerInfoPromptShown = false;
let pendingRetailerCheckoutAfterCustomerInfo = false;
let activeCategory = 'All';
let isManualTaxOverride = false;
let lastTouchedCartId = null;

function getCartRowId(row) {
    if (!row) return '';

    if (row.dataset.id) return String(row.dataset.id);
    if (row.dataset.cartId) return String(row.dataset.cartId);

    const holder = row.querySelector('[data-id]');
    if (holder && holder.dataset.id) {
        return String(holder.dataset.id);
    }

    return '';
}

function arrangeCartLatestFirst() {
    const cart = document.getElementById('cart');
    if (!cart) return;

    const rows = Array.from(cart.querySelectorAll('[data-cart-item]'));
    if (!rows.length) return;

    if (lastTouchedCartId !== null && lastTouchedCartId !== '') {
        const touchedRow = rows.find(row => getCartRowId(row) === String(lastTouchedCartId));
        if (touchedRow) {
            cart.prepend(touchedRow);
        }
    }
}

const custModal = new bootstrap.Modal(document.getElementById('custModal'));
const newCustomerModal = new bootstrap.Modal(document.getElementById('newCustomerModal'));
const priceTierModal = new bootstrap.Modal(document.getElementById('priceTierModal'));
const productUnitModalElement =
    document.getElementById('productUnitModal');

const productUnitModal = productUnitModalElement
    ? new bootstrap.Modal(productUnitModalElement)
    : null;

let pendingUnitProduct = null;

if (productUnitModalElement) {
    productUnitModalElement.addEventListener(
        'hidden.bs.modal',
        function () {
            pendingUnitProduct = null;

            const optionsBox =
                document.getElementById('productUnitOptions');

            if (optionsBox) {
                optionsBox.innerHTML = '';
            }
        }
    );
}
const walkinCustomerModalEl = document.getElementById('walkinCustomerModal');
if (walkinCustomerModalEl) {
    walkinCustomerModalEl.addEventListener('hidden.bs.modal', function () {
        if (!retailerCustomerInfoPromptShown) {
            pendingRetailerCheckoutAfterCustomerInfo = false;
        }
    });
}
let pendingTierProduct = null;

function openNewCustomerModal() {
    newCustomerModal.show();
}

function normalizeWhatsappPhoneForPos(phone) {
    let digits = String(phone || '').replace(/\D+/g, '');

    if (!digits) return '';

    if (digits.startsWith('00')) {
        digits = digits.substring(2);
    }

    if (digits.startsWith('0')) {
        digits = '92' + digits.substring(1);
    } else if (digits.length === 10 && digits.startsWith('3')) {
        digits = '92' + digits;
    }

    return digits;
}

function updateWalkinWhatsappPreview() {
    const phoneInput = document.getElementById('walkinCustomerPhone');
    const preview = document.getElementById('walkinWhatsappPreview');

    if (!phoneInput || !preview) return;

    const waPhone = normalizeWhatsappPhoneForPos(phoneInput.value || '');

    if (waPhone) {
        preview.href = 'https://wa.me/' + encodeURIComponent(waPhone);
        preview.style.display = 'inline-block';
    } else {
        preview.href = '#';
        preview.style.display = 'none';
    }
}

function openRetailerCustomerInfoPopup(force = false) {
    if (selectedType !== 'Retailer') return;
    if (selectedCustomerId && !force) return;

    const modalEl = document.getElementById('walkinCustomerModal');
    if (!modalEl) return;

    const nameInput = document.getElementById('walkinCustomerName');
    const phoneInput = document.getElementById('walkinCustomerPhone');

    if (nameInput) {
        const currentName = (selectedCustomerName || '').trim();
        nameInput.value = (currentName && currentName !== 'Retailer / Walk-In') ? currentName : '';
    }

    if (phoneInput) {
        phoneInput.value = selectedCustomerPhone || '';
    }

    updateWalkinWhatsappPreview();
    bootstrap.Modal.getOrCreateInstance(modalEl).show();

    setTimeout(() => {
        if (nameInput) nameInput.focus();
    }, 250);
}

function saveRetailerCustomerInfo() {
    const modalEl = document.getElementById('walkinCustomerModal');
    const nameInput = document.getElementById('walkinCustomerName');
    const phoneInput = document.getElementById('walkinCustomerPhone');

    const customerName = (nameInput?.value || '').trim();
    const customerPhone = (phoneInput?.value || '').trim();
    const shouldContinueCheckout = pendingRetailerCheckoutAfterCustomerInfo;

    selectedCustomerId = '';
    selectedCustomerName = customerName || 'Retailer / Walk-In';
    selectedCustomerPhone = customerPhone;
    retailerCustomerInfoPromptShown = true;
    pendingRetailerCheckoutAfterCustomerInfo = false;

    updateSelectedCustomerBox();

    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    }

    if (shouldContinueCheckout) {
        setTimeout(() => checkout(), 250);
    }
}

function skipRetailerCustomerInfo() {
    const shouldContinueCheckout = pendingRetailerCheckoutAfterCustomerInfo;
    retailerCustomerInfoPromptShown = true;
    pendingRetailerCheckoutAfterCustomerInfo = false;

    const modalEl = document.getElementById('walkinCustomerModal');

    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    }

    if (shouldContinueCheckout) {
        setTimeout(() => checkout(), 250);
    }
}

function maybeOpenRetailerCustomerInfoPopupAfterAdd() {
    /*
    |--------------------------------------------------------------------------
    | Product add par popup nahi dikhana.
    | Customer info popup ab sirf Proceed To Invoice click ke baad aayega.
    |--------------------------------------------------------------------------
    */
    return;
}

function getCustomerLookupType(type) {
    if (isWholesaleMode(type)) {
        if (!hasWholesaleCustomer) {
            return 'Retailer';
        }
        return 'Wholesaler';
    }

    return type;
}

function getCheckoutCustomerType() {
    if (isWholesaleMode(selectedType)) {
        if (!hasWholesaleCustomer) {
            return 'Retailer';
        }
        return 'Wholesaler';
    }

    return 'Retailer';
}

function getDisplayTypeLabel(type) {
    if (type === 'Wholesale1') return 'WHOLESALE 1';
    if (type === 'Wholesale2') return 'WHOLESALE 2';
    if (type === 'Wholesale3') return 'WHOLESALE 3';
    if (type === 'Retailer') return 'RETAILER';
    return String(type || '').toUpperCase();
}

function getDefaultCustomerName(type) {
    if (isWholesaleMode(type)) {
        return getDisplayTypeLabel(type) + ' Customer';
    }

    return 'Retailer / Walk-In';
}

function openCurrentCustomerSelector() {
    if (selectedType === 'Retailer') {
        openRetailerCustomerInfoPopup(true);
        return;
    }

    const customerType = getCustomerLookupType(selectedType);
    loadCustomers(customerType, selectedType);
    custModal.show();
}

function clearAllCartItems() {
    const ids = Array.from(document.querySelectorAll('[data-cart-remove]')).map(btn => btn.dataset.id).filter(Boolean);

    if (!ids.length) {
        loadCart();
        return;
    }

    if (!confirm('Clear all cart items?')) return;

    let chain = Promise.resolve();
    ids.forEach(id => {
        chain = chain.then(() => fetch('ajax_add_to_cart.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=remove&id=${encodeURIComponent(id)}`
        }).then(handleAjaxSession));
    });

    chain.then(result => {
        if (result === null) return;
        loadCart();
    });
}


function getPriceForSelectedType(card) {
    const originalPrice = parseFloat(card.dataset.originalPrice || card.dataset.retailPrice || card.dataset.basePrice || 0);
    const retailPrice = originalPrice;

    if (!hasWholesaleCustomer) {
        return retailPrice;
    }

    const wholesale1 = parseFloat(card.dataset.wholesalePrice1 || 0);
    const wholesale2 = parseFloat(card.dataset.wholesalePrice2 || 0);
    const wholesale3 = parseFloat(card.dataset.wholesalePrice3 || 0);

    if (selectedType === 'Wholesale1') {
        return wholesale1 > 0 ? wholesale1 : originalPrice;
    }

    if (selectedType === 'Wholesale2') {
        return wholesale2 > 0 ? wholesale2 : originalPrice;
    }

    if (selectedType === 'Wholesale3') {
        return wholesale3 > 0 ? wholesale3 : originalPrice;
    }

    return retailPrice;
}


function getPriceTierMetaForSelectedType() {
    if (!hasWholesaleCustomer) {
        return { tierType: 'default', tierNo: 0, tierLabel: 'Default' };
    }

    if (selectedType === 'Wholesale1') {
        return { tierType: 'wholesale', tierNo: 1, tierLabel: 'Wholesale 1' };
    }

    if (selectedType === 'Wholesale2') {
        return { tierType: 'wholesale', tierNo: 2, tierLabel: 'Wholesale 2' };
    }

    if (selectedType === 'Wholesale3') {
        return { tierType: 'wholesale', tierNo: 3, tierLabel: 'Wholesale 3' };
    }

    return { tierType: 'default', tierNo: 0, tierLabel: 'Default' };
}

function updateProductDisplayedPrices() {
    document.querySelectorAll('.product-card').forEach(card => {
        const price = getPriceForSelectedType(card);
        const holder = card.querySelector('.card-price-value');
        const oldPrice = card.querySelector('.item-old-price');
        const discountBadge = card.querySelector('.item-discount-badge');

        if (holder) {
            holder.textContent = Math.round(price).toLocaleString('en-US');
        }

        if (oldPrice) {
            oldPrice.style.display = 'none';
        }

        if (discountBadge) {
            discountBadge.style.display = selectedType === 'Retailer' ? '' : 'none';
        }
    });
}

function updateSelectedCustomerBox() {
    const defaultName = getDefaultCustomerName(selectedType);
    document.getElementById('selectedCustomerName').textContent = selectedCustomerName || defaultName;
    document.getElementById('selectedCustomerPhone').textContent = selectedCustomerPhone ? selectedCustomerPhone : (selectedType === 'Retailer' ? 'Walk-in / optional customer' : 'No phone');
    document.getElementById('cartCustomerTitle').textContent = selectedCustomerName || defaultName;
    document.getElementById('selectedTypeLabel').textContent = getDisplayTypeLabel(selectedType);

    const phoneInput = document.getElementById('selectedCustomerPhoneInput');
    if (phoneInput) {
        phoneInput.value = selectedCustomerPhone || '';
        phoneInput.placeholder = selectedCustomerPhone ? '' : 'Enter phone number (optional)';
    }

    const waLink = document.getElementById('selectedCustomerWhatsappLink');
    if (waLink) {
        const waPhone = normalizeWhatsappPhoneForPos(selectedCustomerPhone || '');

        if (waPhone) {
            waLink.href = 'https://wa.me/' + encodeURIComponent(waPhone);
            waLink.style.display = 'inline-flex';
        } else {
            waLink.href = '#';
            waLink.style.display = 'none';
        }
    }
}
updateSelectedCustomerBox();

document.querySelectorAll('.type-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        const requestedType = this.dataset.type;

        if (!guardWholesaleMode(requestedType)) {
            return;
        }

        document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');

        selectedType = requestedType;
        updateProductDisplayedPrices();

        selectedCustomerId = '';
        selectedCustomerName = getDefaultCustomerName(selectedType);
        selectedCustomerPhone = '';
        retailerCustomerInfoPromptShown = false;
        updateSelectedCustomerBox();

        /*
        |--------------------------------------------------------------------------
        | Retailer = Walk-In sale + Retail customer
        |--------------------------------------------------------------------------
        | Retailer mode me customer select karna optional hai.
        | Button click par customer modal force open nahi hoga.
        | Change button se retailer customer optional select ho sakta hai.
        */
        if (selectedType === 'Retailer') {
            updateWalkInCartScroller();
            return;
        }

        const customerType = getCustomerLookupType(selectedType);
        loadCustomers(customerType, selectedType);
        custModal.show();
        updateWalkInCartScroller();
    });
});

function loadCustomers(type, selectedMode = type) {
    const list = document.getElementById('custList');
    list.innerHTML = '';

    const rows = customers[type] || [];

    if (!rows.length) {
        list.innerHTML = `<div class="text-secondary p-3">No ${type} customers found.</div>`;
        return;
    }

    rows.forEach(c => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'list-group-item list-group-item-action';
        item.style.background = '#0f1625';
        item.style.color = '#fff';
        item.style.border = '1px solid rgba(255,255,255,.06)';
        item.style.marginBottom = '8px';
        item.style.borderRadius = '12px';
        item.innerHTML = `
            <div class="fw-bold">${escapeHtml(c.name || '')}</div>
            <small class="text-secondary">${escapeHtml(c.phone || '')}</small>
        `;
        item.onclick = function () {
            selectedCustomerId = c.id || '';
            selectedCustomerName = c.name || getDefaultCustomerName(selectedMode);
            selectedCustomerPhone = c.phone || '';
            retailerCustomerInfoPromptShown = true;
            updateSelectedCustomerBox();
            updateWalkInCartScroller();
            custModal.hide();
        };
        list.appendChild(item);
    });
}

document.getElementById('walkinCustomerPhone')?.addEventListener('input', updateWalkinWhatsappPreview);
document.getElementById('walkinCustomerName')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        saveRetailerCustomerInfo();
    }
});
document.getElementById('walkinCustomerPhone')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        saveRetailerCustomerInfo();
    }
});

document.getElementById('searchCustomer').addEventListener('keyup', function () {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('#custList .list-group-item').forEach(li => {
        li.style.display = li.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
});

const categoryButtons = Array.from(document.querySelectorAll('.category-btn'));
const productCards = Array.from(document.querySelectorAll('#itemsGrid .product-card'));
const productSearchInput = document.getElementById('productSearch');
let productFilterFrame = 0;

categoryButtons.forEach(btn => {
    btn.addEventListener('click', function () {
        categoryButtons.forEach(button => button.classList.remove('active'));
        this.classList.add('active');
        activeCategory = this.dataset.category;
        scheduleProductFilter();
    });
});

if (productSearchInput) {
    productSearchInput.addEventListener('input', scheduleProductFilter, {passive: true});
}

function scheduleProductFilter() {
    if (productFilterFrame) {
        cancelAnimationFrame(productFilterFrame);
    }

    productFilterFrame = requestAnimationFrame(() => {
        productFilterFrame = 0;
        filterProducts();
    });
}

function filterProducts() {
    if (!productSearchInput) {
        return;
    }

    const q = productSearchInput.value.toLowerCase().trim();
    const normalizedCategory = String(activeCategory || 'All').toLowerCase();
    let visibleCount = 0;

    productCards.forEach(card => {
        const category = (card.dataset.category || 'All').trim().toLowerCase();
        const searchableText = (
            card.dataset.searchText ||
            card.dataset.name ||
            card.textContent ||
            ''
        ).toLowerCase();

        const matchCategory =
            normalizedCategory === 'all' ||
            category === normalizedCategory;

        const shouldShow =
            matchCategory &&
            (q === '' || searchableText.includes(q));

        card.classList.toggle('pos-search-hidden', !shouldShow);

        if (shouldShow) {
            visibleCount++;
        }
    });

    const productCountEl = document.getElementById('productCount');

    if (productCountEl) {
        productCountEl.textContent = visibleCount;
    }
}

document.getElementById('cartSearch').addEventListener('input', filterCartItems);

function filterCartItems() {
    const q = document.getElementById('cartSearch').value.toLowerCase().trim();
    const items = document.querySelectorAll('#cart [data-cart-item]');
    let visible = 0;

    items.forEach(item => {
        const name = (item.dataset.name || '').toLowerCase();
        const sku = (item.dataset.sku || '').toLowerCase();
        const barcode = (item.dataset.barcode || '').toLowerCase();

        const matched =
            !q ||
            name.includes(q) ||
            sku.includes(q) ||
            barcode.includes(q);

        item.style.display = matched ? '' : 'none';

        if (matched) {
            visible++;
        }
    });

    const emptyState = document.querySelector('#cart .empty-state');
    if (!visible && items.length > 0 && !emptyState) {
        const msg = document.createElement('div');
        msg.className = 'empty-state cart-search-empty';
        msg.textContent = 'No matching cart item found';
        document.getElementById('cart').appendChild(msg);
    } else {
        const oldMsg = document.querySelector('#cart .cart-search-empty');
        if (oldMsg) oldMsg.remove();
    }
}

function updateWalkInCartScroller() {
    const cart = document.getElementById('cart');
    if (!cart) return;
    cart.classList.remove('walkin-scroll');
}
function setAutoTaxField(rate) {
    const taxEl = document.getElementById('tax');
    if (!taxEl) return;

    taxEl.placeholder = 'Tax %';
    if (rate > 0) {
        taxEl.placeholder = 'Tax % (Auto: ' + Number(rate).toFixed(2) + '%)';
    }

    if (!isManualTaxOverride) {
        taxEl.value = rate > 0 ? Number(rate).toFixed(2) : '';
    }
}

function detectCartAutoTaxRate() {
    let highestTaxRate = 0;

    document.querySelectorAll('[data-cart-item]').forEach(item => {
        const itemType = (item.dataset.itemType || '').toLowerCase();
        const sourceId = parseInt(item.dataset.sourceId || 0, 10);
        let taxRate = parseFloat(item.dataset.taxRate || 0);

        if ((!taxRate || taxRate <= 0) && sourceId > 0 && productTaxMap[sourceId]) {
            taxRate = parseFloat(productTaxMap[sourceId].tax_rate || 0);
        }

        if (itemType === 'product' && taxRate > highestTaxRate) {
            highestTaxRate = taxRate;
        }
    });

    if (highestTaxRate <= 0) {
        highestTaxRate = defaultProductTaxRate;
    }

    return highestTaxRate;
}

function findProductImageForCartRow(row) {
    const sourceId = row.dataset.sourceId || row.dataset.itemId || row.dataset.productId || '';
    let img = '';

    if (sourceId) {
        const card = document.querySelector(`.product-card[data-id="${CSS.escape(String(sourceId))}"]`);
        const cardImg = card ? card.querySelector('.item-img img') : null;

        if (cardImg) {
            img = cardImg.getAttribute('src') || '';
        }
    }

    if (!img) {
        const rowName = (row.dataset.name || '').trim().toLowerCase();

        if (rowName) {
            document.querySelectorAll('.product-card').forEach(card => {
                if (img) return;

                const cardName = (card.dataset.name || '').trim().toLowerCase();

                if (cardName === rowName) {
                    const cardImg = card.querySelector('.item-img img');

                    if (cardImg) {
                        img = cardImg.getAttribute('src') || '';
                    }
                }
            });
        }
    }

    return img || 'https://via.placeholder.com/80x80?text=Item';
}

function formatRefMoney(value, decimals = 0) {
    value = Number(value || 0);
    if (!Number.isFinite(value)) value = 0;

    return value.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

function getRefBatchValue(row) {
    const sku = (row.dataset.sku || '').trim();
    const barcode = (row.dataset.barcode || '').trim();

    if (barcode) return barcode;
    if (sku) return sku;

    return '—';
}

function ensureRefCell(row, selector, className) {
    let cell = row.querySelector(selector);

    if (!cell) {
        cell = document.createElement('div');
        cell.className = className;
        row.appendChild(cell);
    }

    return cell;
}

window.__posLineDiscounts = window.__posLineDiscounts || {};

function getCartRowKey(row) {
    if (!row) return '';

    return (
        row.dataset.id ||
        row.getAttribute('data-id') ||
        row.dataset.cartId ||
        row.getAttribute('data-cart-id') ||
        row.dataset.sourceId ||
        row.dataset.itemId ||
        row.dataset.productId ||
        ''
    );
}
function normalizePercentValue(value) {
    let percent = parseFloat(value || 0);

    if (!Number.isFinite(percent) || percent < 0) {
        percent = 0;
    }

    if (percent > 100) {
        percent = 100;
    }

    return percent;
}

function formatPercentInputValue(value) {
    value = normalizePercentValue(value);

    return Number.isInteger(value) ? String(value) : value.toFixed(2);
}

function updateReferenceLineDiscountPreview() {
    const discountInput = document.getElementById('discount');
    const requestedDiscount = normalizePercentValue(discountInput?.value || 0);

    document.querySelectorAll('#cart [data-cart-item]').forEach(row => {
        const qtyInput = row.querySelector('[data-cart-qty-input]');
        const priceInput = row.querySelector('[data-cart-price-input]');

        const qty = Math.max(1, parseFloat(qtyInput?.value || row.dataset.qty || 1) || 1);
        const price = Math.max(0, parseFloat(priceInput?.value || 0) || 0);
        const lineSubtotal = qty * price;

        const discountCell = row.querySelector('.pos-ref-discount-cell');
        if (!discountCell) return;

        const input = discountCell.querySelector('.pos-line-discount-input');
        const text = discountCell.querySelector('.pos-line-discount-text');

        const rowKey = getCartRowKey(row);
        const savedManualDiscount = rowKey && window.__posLineDiscounts
            ? window.__posLineDiscounts[rowKey]
            : undefined;

        let appliedDiscount;

        if (input && (input.dataset.manualDiscount === '1' || savedManualDiscount !== undefined)) {
            appliedDiscount = normalizePercentValue(savedManualDiscount !== undefined ? savedManualDiscount : input.value);
            input.dataset.manualDiscount = '1';
        } else {
            const hasMaxDiscount = String(row.dataset.hasMaxDiscount || '0') === '1';
            const maxDiscountPercent = parseFloat(row.dataset.maxDiscountPercent || 0) || 0;

            appliedDiscount = requestedDiscount;

            if (hasMaxDiscount && maxDiscountPercent > 0) {
                appliedDiscount = Math.min(requestedDiscount, maxDiscountPercent);
            }
        }

        const discountAmount = lineSubtotal * (appliedDiscount / 100);

        row.dataset.appliedDiscountPercent = appliedDiscount.toFixed(2);
        row.dataset.lineDiscount = discountAmount.toFixed(2);

        if (input) {
            input.value = formatPercentInputValue(appliedDiscount);
        }

        if (text) {
            text.textContent = 'Discounted: ' + formatRefMoney(discountAmount, 2);
        }
    });
}


function enhanceCartRowsForReferenceUI() {
    const cart = document.getElementById('cart');
    if (!cart) return;

    const rows = Array.from(cart.querySelectorAll('[data-cart-item]'));
    const counter = document.getElementById('cartItemsCount');

    if (counter) {
        counter.textContent = rows.length;
    }

    cart.classList.toggle('has-cart-items', rows.length > 0);

    rows.forEach(row => {
        row.classList.add('pos-ref-row', 'ref-row-ready', 'pos-cart-unit-row-ready');

        const left = row.querySelector('.pos-cart-left');
        const oldRight = row.querySelector('.pos-cart-right');
        const controls = row.querySelector('.pos-cart-controls');
        const priceInput = row.querySelector('[data-cart-price-input]');
        const lineTotal = row.querySelector('[data-line-total]');
        const removeBtn = row.querySelector('[data-cart-remove]');

        if (left) {
            left.classList.add('pos-ref-product-cell');

            let textBox = left.querySelector('.pos-ref-product-text');

            if (!textBox) {
                textBox = document.createElement('div');
                textBox.className = 'pos-ref-product-text';

                const head = left.querySelector('.pos-cart-head');
                if (head) {
                    textBox.appendChild(head);
                }

                left.appendChild(textBox);
            }
        }

        const unitCell = ensureRefCell(row, '.pos-ref-unit-cell', 'pos-ref-unit-cell');
        renderCartUnitSelector(row, unitCell);

        const batchCell = row.querySelector('.pos-ref-batch-cell');
        if (batchCell) {
            batchCell.remove();
        }

        const qtyCell = ensureRefCell(row, '.pos-ref-qty-cell', 'pos-ref-qty-cell');
        if (controls && controls.parentElement !== qtyCell) {
            qtyCell.appendChild(controls);
        }

        const priceCell = ensureRefCell(row, '.pos-ref-price-cell', 'pos-ref-price-cell');
        if (priceInput && priceInput.parentElement !== priceCell) {
            priceCell.appendChild(priceInput);
        }

        const discountCell = ensureRefCell(row, '.pos-ref-discount-cell', 'pos-ref-discount-cell');
        if (!discountCell.querySelector('.pos-line-discount-control')) {
            const rowKey = getCartRowKey(row);
            const savedDiscount = rowKey && window.__posLineDiscounts
                ? window.__posLineDiscounts[rowKey]
                : undefined;

            const hasSavedDiscount = savedDiscount !== undefined;
            const discountValue = hasSavedDiscount ? normalizePercentValue(savedDiscount) : 0;

            discountCell.innerHTML = `
                <div class="pos-line-discount-control">
                    <input
                        type="number"
                        class="pos-line-discount-input"
                        value="${formatPercentInputValue(discountValue)}"
                        min="0"
                        max="100"
                        step="0.01"
                        data-line-discount-input="1"
                        ${hasSavedDiscount ? 'data-manual-discount="1"' : ''}
                    >
                    <span>%</span>
                </div>
                <div class="pos-line-discount-text">Discounted: 0.00</div>
            `;
        }

        const totalCell = ensureRefCell(row, '.pos-ref-total-cell', 'pos-ref-total-cell');
        if (lineTotal && lineTotal.parentElement !== totalCell) {
            totalCell.appendChild(lineTotal);
        }

        const removeCell = ensureRefCell(row, '.pos-ref-remove-cell', 'pos-ref-remove-cell');
        if (removeBtn && removeBtn.parentElement !== removeCell) {
            removeCell.appendChild(removeBtn);
        }

        if (oldRight) {
            oldRight.remove();
        }
    });

    updateReferenceLineDiscountPreview();
}


document.addEventListener('input', function (e) {
    if (!e.target || typeof e.target.matches !== 'function') return;

    if (e.target.matches('.pos-line-discount-input')) {
        const row = e.target.closest('[data-cart-item]');
        const rowKey = getCartRowKey(row);
        const value = normalizePercentValue(e.target.value);

        e.target.value = formatPercentInputValue(value);
        e.target.dataset.manualDiscount = '1';

        if (rowKey) {
            window.__posLineDiscounts[rowKey] = value;
        }

        updateReferenceLineDiscountPreview();

        if (typeof calculateSummaryFromDom === 'function') {
            calculateSummaryFromDom();
        }

        return;
    }

    if (
        e.target.id === 'discount' ||
        e.target.matches('[data-cart-qty-input]') ||
        e.target.matches('[data-cart-price-input]')
    ) {
        updateReferenceLineDiscountPreview();

        if (typeof calculateSummaryFromDom === 'function') {
            calculateSummaryFromDom();
        }
    }
});
function refreshCartHtml(html) {
    document.getElementById('cart').innerHTML = html.trim() ? html : '<div class="empty-state">Cart is empty</div>';
    arrangeCartLatestFirst();
    enhanceCartRowsForReferenceUI();
    bindCartControls();
    const autoTaxRate = detectCartAutoTaxRate();
    setAutoTaxField(autoTaxRate);
    calculateSummaryFromDom();
    updateWalkInCartScroller();
    filterCartItems();
}

function loadCart() {
    fetch('ajax_add_to_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=render'
    })
    .then(handleAjaxSession)
    .then(html => {
        if (html === null) return;
        refreshCartHtml(html);
    });
}

function safeParseJson(value, fallback = []) {
    try {
        const parsed = JSON.parse(value || '[]');
        return Array.isArray(parsed) ? parsed : fallback;
    } catch (e) {
        return fallback;
    }
}

function normalizeProductUnitMeta(unit = {}) {
    const id = parseInt(unit.id || unit.unit_id || 0, 10) || 0;
    const name = String(unit.unit_name || unit.name || '').trim();
    const price = parseFloat(unit.sale_price || unit.price || unit.unit_price || 0) || 0;
    const conversion = parseFloat(unit.conversion_qty || unit.unit_conversion_qty || unit.conversion || 1) || 1;
    const isDefault = String(unit.is_default || '0') === '1';

    return { id, name, price, conversion, isDefault };
}

function getProductUnitsForProductId(productId) {
    if (!PHARMACY_UNIT_ENABLED || !productId) return [];

    const rows = PRODUCT_UNIT_MAP && PRODUCT_UNIT_MAP[String(productId)]
        ? PRODUCT_UNIT_MAP[String(productId)]
        : [];

    return Array.isArray(rows)
        ? rows.map(normalizeProductUnitMeta).filter(unit => unit.id > 0 && unit.name !== '')
        : [];
}

function getProductUnitsFromCard(card) {
    if (!PHARMACY_UNIT_ENABLED || !card) return [];

    const fromMap = getProductUnitsForProductId(card.dataset.id || '');
    if (fromMap.length) return fromMap;

    return safeParseJson(card.dataset.productUnits || '[]', [])
        .map(normalizeProductUnitMeta)
        .filter(unit => unit.id > 0 && unit.name !== '');
}

function getDefaultProductUnitForCard(card) {
    if (!PHARMACY_UNIT_ENABLED || !card) return null;

    const units = getProductUnitsFromCard(card);
    if (!units.length) return null;

    const defaultId = parseInt(card.dataset.defaultUnitId || '0', 10) || 0;
    return units.find(unit => unit.id === defaultId) || units.find(unit => unit.isDefault) || units[0];
}

/* Compatibility name: ab product card par dropdown nahi hai, default unit silently add hogi. */
function getSelectedProductUnit(card) {
    return getDefaultProductUnitForCard(card);
}

/* Compatibility only: product card unit dropdown removed. */



function getCartUnitRowsForRow(row) {
    if (!PHARMACY_UNIT_ENABLED || !row) return [];
    const productId = row.dataset.sourceId || row.dataset.itemId || row.dataset.productId || '';
    return getProductUnitsForProductId(productId);
}

function renderCartUnitSelector(row, unitCell) {
    if (!unitCell || !row) return;

    const units = getCartUnitRowsForRow(row);
    const currentUnitId = parseInt(row.dataset.unitId || '0', 10) || 0;
    const currentName = String(row.dataset.unitName || '').trim();

    if (!PHARMACY_UNIT_ENABLED || !units.length) {
        unitCell.innerHTML = currentName
            ? `<span class="pos-cart-unit-static">${escapeHtml(currentName)}</span>`
            : `<span class="pos-cart-unit-static muted">—</span>`;
        return;
    }

    const selectedUnit = units.find(unit => unit.id === currentUnitId)
        || units.find(unit => unit.isDefault)
        || units[0];

    unitCell.innerHTML = `
        <select class="pos-cart-unit-select" data-cart-unit-select="1" onchange="handleCartUnitChange(this)">
            ${units.map(unit => `
                <option
                    value="${unit.id}"
                    data-unit-name="${escapeHtml(unit.name)}"
                    data-price="${unit.price}"
                    data-conversion="${unit.conversion}"
                    ${selectedUnit && unit.id === selectedUnit.id ? 'selected' : ''}
                >${escapeHtml(unit.name)}</option>
            `).join('')}
        </select>
    `;

    if (selectedUnit) {
        row.dataset.unitId = String(selectedUnit.id);
        row.dataset.unitName = selectedUnit.name;
        row.dataset.unitConversionQty = String(selectedUnit.conversion);
    }
}

function deriveCartKeyForUnit(productId, unitId, priceTierType = 'default', priceTierNo = 0) {
    let key = 'item_' + productId;

    if (unitId > 0) {
        key += '_unit_' + unitId;
    }

    if (priceTierType === 'wholesale' && parseInt(priceTierNo || 0, 10) > 0) {
        key += '_wholesale_' + parseInt(priceTierNo || 0, 10);
    }

    return key;
}

function postPosCart(body) {
    return fetch('ajax_add_to_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body
    }).then(handleAjaxSession);
}

function handleCartUnitChange(select) {
    if (!PHARMACY_UNIT_ENABLED || !select) return;

    const row = select.closest('[data-cart-item]');
    if (!row) return;

    const option = select.options[select.selectedIndex];
    if (!option) return;

    const productId = parseInt(row.dataset.sourceId || row.dataset.itemId || row.dataset.productId || '0', 10) || 0;
    if (productId <= 0) return;

    const oldKey = getCartRowKey(row);
    const qtyInput = row.querySelector('[data-cart-qty-input]');
    const qty = Math.max(1, parseFloat(qtyInput?.value || row.dataset.qty || 1) || 1);
    const unitId = parseInt(select.value || '0', 10) || 0;
    const unitName = option.dataset.unitName || option.textContent.trim();
    const unitPrice = parseFloat(option.dataset.price || '0') || 0;
    const unitConversion = parseFloat(option.dataset.conversion || '1') || 1;
    const taxRate = parseFloat(row.dataset.taxRate || 0) || 0;
    const itemType = row.dataset.itemType || 'product';
    const tierMeta = getPriceTierMetaForSelectedType();
    const newKey = deriveCartKeyForUnit(productId, unitId, tierMeta.tierType, tierMeta.tierNo);
    const oldDiscount = oldKey && window.__posLineDiscounts ? window.__posLineDiscounts[oldKey] : undefined;

    select.disabled = true;
    lastTouchedCartId = newKey;

    const addBody =
        'action=add' +
        '&id=' + encodeURIComponent(productId) +
        '&tax_rate=' + encodeURIComponent(taxRate) +
        '&item_type=' + encodeURIComponent(itemType) +
        '&selected_price=' + encodeURIComponent(unitPrice) +
        '&price_tier_type=' + encodeURIComponent(tierMeta.tierType) +
        '&price_tier_no=' + encodeURIComponent(tierMeta.tierNo) +
        '&price_tier_label=' + encodeURIComponent(tierMeta.tierLabel) +
        '&unit_id=' + encodeURIComponent(unitId) +
        '&unit_name=' + encodeURIComponent(unitName) +
        '&unit_conversion_qty=' + encodeURIComponent(unitConversion);

    const updateBody =
        'action=update' +
        '&id=' + encodeURIComponent(newKey) +
        '&qty=' + encodeURIComponent(qty) +
        '&price=' + encodeURIComponent(unitPrice);

    let chain = Promise.resolve('');

    if (oldKey && oldKey !== newKey) {
        chain = chain.then(() => postPosCart('action=remove&id=' + encodeURIComponent(oldKey)));
    }

    chain
        .then(() => postPosCart(addBody))
        .then(html => {
            if (html === null) return null;
            if (qty > 1 || oldKey === newKey) {
                return postPosCart(updateBody);
            }
            return html;
        })
        .then(html => {
            if (html === null) return;

            if (oldDiscount !== undefined && window.__posLineDiscounts) {
                delete window.__posLineDiscounts[oldKey];
                window.__posLineDiscounts[newKey] = oldDiscount;
            }

            refreshCartHtml(html);
        })
        .catch(err => {
            console.error(err);
            alert('Unit change failed. Please try again.');
            loadCart();
        });
}


function handleProductUnitChange(select) {
    if (!PHARMACY_UNIT_ENABLED || !select) {
        return;
    }

    const card = select.closest('.product-card');
    if (!card) {
        return;
    }

    const unit = getSelectedProductUnit(card);
    if (!unit) {
        return;
    }

    /*
    | Retailer mode mein unit price show hoga.
    | Wholesale mode ko existing wholesale logic handle karega.
    */
    if (!isWholesaleMode(selectedType)) {
        card.dataset.basePrice = String(unit.price);
        card.dataset.retailPrice = String(unit.price);
        card.dataset.originalPrice = String(unit.price);

        const holder = card.querySelector('.card-price-value');
        if (holder) {
            holder.textContent = Math.round(unit.price).toLocaleString('en-US');
        }
    }

    updateProductDisplayedPrices();
}


function addToCartFromCard(
    card,
    id,
    taxRate = 0,
    itemType = 'product'
) {
    if (!card || !id) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Existing wholesale guard unchanged
    |--------------------------------------------------------------------------
    */
    if (
        isWholesaleMode(selectedType) &&
        !hasWholesaleCustomer
    ) {
        selectedType = 'Retailer';
    }

    const tierMeta =
        getPriceTierMetaForSelectedType();

    const productUnits =
        getProductUnitsFromCard(card);

    /*
    |--------------------------------------------------------------------------
    | New pharmacy unit popup workflow
    |--------------------------------------------------------------------------
    | Retailer mode + units available:
    | Product direct cart mein nahi jayega.
    | Pehle unit selection popup open hoga.
    |--------------------------------------------------------------------------
    */
 if (
    PHARMACY_UNIT_ENABLED &&
    !isWholesaleMode(selectedType) &&
    productUnits.length > 1
) {
        openProductUnitPopup(
            card,
            id,
            taxRate,
            itemType,
            productUnits,
            tierMeta
        );

        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Existing fallback workflow
    |--------------------------------------------------------------------------
    | Product units nahi hain ya wholesale mode hai to old logic chalega.
    |--------------------------------------------------------------------------
    */
    const defaultUnit =
        getDefaultProductUnitForCard(card);

    let selectedPrice =
        getPriceForSelectedType(card);

    if (
        defaultUnit &&
        !isWholesaleMode(selectedType)
    ) {
        selectedPrice = defaultUnit.price;
    }

    addToCart(
        id,
        taxRate,
        itemType,
        selectedPrice,
        tierMeta.tierType,
        tierMeta.tierNo,
        tierMeta.tierLabel,
        defaultUnit ? defaultUnit.id : 0,
        defaultUnit ? defaultUnit.name : '',
        defaultUnit ? defaultUnit.conversion : 1
    );
}

/* =====================================================
   OPEN PRODUCT UNIT POPUP
===================================================== */
function openProductUnitPopup(
    card,
    id,
    taxRate,
    itemType,
    units,
    tierMeta
) {
    if (
        !productUnitModal ||
        !card ||
        !Array.isArray(units) ||
        units.length === 0
    ) {
        return false;
    }

    pendingUnitProduct = {
        card: card,
        id: parseInt(id || 0, 10),
        taxRate: parseFloat(taxRate || 0),
        itemType: itemType || 'product',
        units: units,
        tierMeta: tierMeta
    };

    const titleElement =
        document.getElementById('productUnitModalTitle');

    const optionsBox =
        document.getElementById('productUnitOptions');

    if (!optionsBox) {
        return false;
    }

    const productName =
        String(card.dataset.name || 'Product').trim();

    if (titleElement) {
        titleElement.textContent =
            'Select Unit — ' + productName;
    }

    optionsBox.innerHTML = '';

    units.forEach(function (unit) {
        const button = document.createElement('button');

        button.type = 'button';
        button.className = 'product-unit-option';

        const conversionText =
            parseFloat(unit.conversion || 1) > 1
                ? 'Contains ' + unit.conversion + ' base units'
                : 'Single base unit';

   button.innerHTML = `
    <span class="product-unit-option-left">
        <span class="product-unit-option-name">
            ${escapeHtml(unit.name)}
        </span>

        <span class="product-unit-option-conversion">
            ${escapeHtml(conversionText)}
        </span>
    </span>

    <span class="product-unit-option-right">
        <span class="product-unit-option-price">
            Rs ${Math.round(unit.price).toLocaleString('en-US')}
        </span>

        <span
            class="product-unit-option-add"
            aria-hidden="true"
        >
            +
        </span>
    </span>
`;

button.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();

    selectProductUnitAndAdd(unit.id);
});

        optionsBox.appendChild(button);
    });

    productUnitModal.show();

    return true;
}


/* =====================================================
   SELECT UNIT AND ADD PRODUCT
===================================================== */
function selectProductUnitAndAdd(unitId) {
    const pending = pendingUnitProduct;

    if (!pending) {
        console.error(
            'Pending unit product data is missing.'
        );
        return;
    }

    const selectedUnit = pending.units.find(function (unit) {
        return parseInt(unit.id || 0, 10) ===
            parseInt(unitId || 0, 10);
    });

    if (!selectedUnit) {
        alert('Selected unit was not found.');
        return;
    }

    const productId =
        parseInt(pending.id || 0, 10);

    const selectedUnitId =
        parseInt(selectedUnit.id || 0, 10);

    const selectedPrice =
        parseFloat(selectedUnit.price || 0);

    const selectedConversion =
        parseFloat(selectedUnit.conversion || 1);

    if (productId <= 0) {
        alert('Invalid product ID.');
        return;
    }

    if (selectedUnitId <= 0) {
        alert('Invalid unit ID.');
        return;
    }

    if (selectedPrice <= 0) {
        alert('Selected unit price is invalid.');
        return;
    }

    const tierMeta = pending.tierMeta || {
        tierType: 'default',
        tierNo: 0,
        tierLabel: 'Default'
    };

    /*
    |--------------------------------------------------------------------------
    | Har click par direct cart request
    |--------------------------------------------------------------------------
    | Button disable nahi hoga.
    | Loading dots nahi aayenge.
    | Tick nahi aayega.
    | Popup open rahega.
    |--------------------------------------------------------------------------
    */
    addToCart(
        productId,
        parseFloat(pending.taxRate || 0),
        pending.itemType || 'product',
        selectedPrice,
        tierMeta.tierType || 'default',
        parseInt(tierMeta.tierNo || 0, 10),
        tierMeta.tierLabel || 'Default',
        selectedUnitId,
        selectedUnit.name || '',
        selectedConversion > 0
            ? selectedConversion
            : 1
    );
}

function addToCart(
    id,
    taxRate = 0,
    itemType = 'product',
    selectedPrice = '',
    priceTierType = 'default',
    priceTierNo = 0,
    priceTierLabel = 'Default',
    unitId = 0,
    unitName = '',
    unitConversionQty = 1
) {
    const productId = parseInt(id || 0, 10);
    const selectedUnitId = parseInt(unitId || 0, 10);
    const selectedUnitConversion = parseFloat(
        unitConversionQty || 1
    );

    if (productId <= 0) {
        console.error('Invalid product ID:', id);
        return Promise.resolve(false);
    }

    const tierSuffix =
        priceTierType !== 'default'
            ? '_' + priceTierType + '_' + priceTierNo
            : '';

    const unitSuffix =
        selectedUnitId > 0
            ? '_unit_' + selectedUnitId
            : '';

    lastTouchedCartId =
        'item_' + productId + unitSuffix + tierSuffix;

    const requestBody = new URLSearchParams();

    requestBody.set('action', 'add');
    requestBody.set('id', String(productId));
    requestBody.set('tax_rate', String(taxRate || 0));
    requestBody.set('item_type', itemType || 'product');
    requestBody.set('selected_price', String(selectedPrice || 0));
    requestBody.set(
        'price_tier_type',
        priceTierType || 'default'
    );
    requestBody.set(
        'price_tier_no',
        String(priceTierNo || 0)
    );
    requestBody.set(
        'price_tier_label',
        priceTierLabel || 'Default'
    );
    requestBody.set(
        'unit_id',
        String(selectedUnitId)
    );
    requestBody.set(
        'unit_name',
        unitName || ''
    );
    requestBody.set(
        'unit_conversion_qty',
        String(
            selectedUnitConversion > 0
                ? selectedUnitConversion
                : 1
        )
    );

    return fetch('ajax_add_to_cart.php', {
        method: 'POST',
        headers: {
            'Content-Type':
                'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: requestBody.toString()
    })
    .then(async function (response) {
        /*
        |--------------------------------------------------------------------------
        | Session handler ko 401 response handle karne do
        |--------------------------------------------------------------------------
        */
        if (response.status === 401) {
            return handleAjaxSession(response);
        }

        /*
        |--------------------------------------------------------------------------
        | PHP/backend error ko silently ignore nahi karna
        |--------------------------------------------------------------------------
        */
        if (!response.ok) {
            const errorText = await response.text();

            throw new Error(
                errorText ||
                ('Cart request failed: HTTP ' + response.status)
            );
        }

        return handleAjaxSession(response);
    })
    .then(function (html) {
        if (html === null) {
            return null;
        }

        const cartHtml = String(html || '').trim();

        if (cartHtml === '') {
            throw new Error(
                'Cart backend returned an empty response.'
            );
        }

        refreshCartHtml(cartHtml);

        return cartHtml;
    })
    .catch(function (error) {
        console.error('Add to cart failed:', error);

        alert(
            'Product cart mein add nahi ho saka. ' +
            'Browser console mein error check karein.'
        );

        return null;
    });
}
/*
|--------------------------------------------------------------------------
| CUSTOM ITEM FORM TOGGLE
|--------------------------------------------------------------------------
| Sirf custom item form ko open/close karta hai.
| Kisi backend ya cart logic ko change nahi karta.
|--------------------------------------------------------------------------
*/
function toggleCustomProductForm() {
    const form = document.querySelector(
        '.pos-ref-bottom-aligned .pos-custom-product-form'
    );

    if (!form) {
        console.error('Custom product form not found.');
        return;
    }

    const isOpened = form.classList.toggle('show');

    const button = document.querySelector(
        '.pos-add-custom-main.pos-ref-add-inline-btn'
    );

    if (button) {
        button.setAttribute('aria-expanded', isOpened ? 'true' : 'false');
    }

    if (isOpened) {
        setTimeout(() => {
            const nameInput = document.getElementById('customProductName');

            if (nameInput) {
                nameInput.focus();
            }
        }, 50);
    }
}

function addCustomProduct() {
    const nameEl = document.getElementById('customProductName');
    const priceEl = document.getElementById('customProductPrice');
    const qtyEl = document.getElementById('customProductQty');

    const name = (nameEl?.value || '').trim();
    const price = parseFloat(priceEl?.value || 0);
    const qty = parseInt(qtyEl?.value || 1, 10);

    if (!name) {
        alert('Please enter product name');
        nameEl.focus();
        return;
    }

    if (price <= 0) {
        alert('Please enter valid price');
        priceEl.focus();
        return;
    }

    if (qty <= 0) {
        alert('Please enter valid quantity');
        qtyEl.focus();
        return;
    }

    lastTouchedCartId = null;

    fetch('ajax_add_to_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body:
            'action=add_custom' +
            '&name=' + encodeURIComponent(name) +
            '&price=' + encodeURIComponent(price) +
            '&qty=' + encodeURIComponent(qty) +
            '&tax_rate=0' +
            '&item_type=custom'
    })
    .then(handleAjaxSession)
    .then(html => {
        if (html === null) return;

        refreshCartHtml(html);

        nameEl.value = '';
        priceEl.value = '';
        qtyEl.value = '1';

        nameEl.focus();
    })
    .catch(error => {
        console.error('Custom product add failed:', error);
        alert('Custom product add failed');
    });
}

function updateCartRealtime(id, qty, price) {
    lastTouchedCartId = id;

    fetch('ajax_add_to_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=update&id=${encodeURIComponent(id)}&qty=${encodeURIComponent(qty)}&price=${encodeURIComponent(price)}`
    })
    .then(handleAjaxSession)
    .then(html => {
        if (html === null) return;
        refreshCartHtml(html);
    });
}

function removeItem(id) {
    fetch('ajax_add_to_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=remove&id=${encodeURIComponent(id)}`
    })
    .then(handleAjaxSession)
    .then(html => {
        if (html === null) return;
        refreshCartHtml(html);
    });
}

function bindCartControls() {
    document.querySelectorAll('[data-cart-minus]').forEach(btn => {
        btn.onclick = function () {
            const id = this.dataset.id;
            const qty = parseFloat(this.dataset.qty || 1);
            const price = parseFloat(this.dataset.price || 0);
            const newQty = qty > 1 ? qty - 1 : 1;
            updateCartRealtime(id, newQty, price);
        };
    });

    document.querySelectorAll('[data-cart-plus]').forEach(btn => {
        btn.onclick = function () {
            const id = this.dataset.id;
            const qty = parseFloat(this.dataset.qty || 1);
            const price = parseFloat(this.dataset.price || 0);
            updateCartRealtime(id, qty + 1, price);
        };
    });

    document.querySelectorAll('[data-cart-remove]').forEach(btn => {
        btn.onclick = function () {
            removeItem(this.dataset.id);
        };
    });

    document.querySelectorAll('[data-cart-qty-input]').forEach(inp => {
        inp.onchange = function () {
            const id = this.dataset.id;
            const price = parseFloat(this.dataset.price || 0);
            const qty = Math.max(1, parseFloat(this.value || 1));
            updateCartRealtime(id, qty, price);
        };
    });

    document.querySelectorAll('[data-cart-price-input]').forEach(inp => {
        inp.type = 'number';
        inp.min = '0';
        inp.step = '1';

        let priceTimer = null;

        const updatePrice = function () {
            const id = this.dataset.id;
            const qty = Math.max(1, parseFloat(this.dataset.qty || 1));
            const price = Math.max(0, Math.round(parseFloat(this.value || 0)));

            lastTouchedCartId = id;
            clearTimeout(priceTimer);

            priceTimer = setTimeout(() => {
                updateCartRealtime(id, qty, price);
            }, 250);
        };

        inp.oninput = updatePrice;
        inp.onchange = updatePrice;
    });
}

function getCartItemMaxDiscountPercent(row) {
    /*
    |--------------------------------------------------------------------------
    | Maximum Discount Limit per item
    |--------------------------------------------------------------------------
    | Product card se max discount read hota hai.
    | Agar item par max limit set nahi hai to null return hoga,
    | yani POS me entered full discount us line par apply hoga.
    */
    if (!row) return null;

    const sourceId = parseInt(row.dataset.sourceId || row.dataset.itemId || row.dataset.productId || 0, 10);

    if (!sourceId || sourceId <= 0) {
        return null;
    }

    const card = document.querySelector(`.product-card[data-id="${CSS.escape(String(sourceId))}"]`);

    if (!card) {
        return null;
    }

    const hasMaxDiscount = parseInt(card.dataset.hasMaxDiscount || card.dataset.hasDiscount || 0, 10) === 1;
    let maxPercent = parseFloat(card.dataset.maxDiscountPercent || card.dataset.discountValue || 0);

    if (!Number.isFinite(maxPercent) || maxPercent <= 0 || !hasMaxDiscount) {
        return null;
    }

    if (maxPercent > 100) {
        maxPercent = 100;
    }

    return maxPercent;
}

function calculateItemWiseCappedDiscount(discountPer) {
    discountPer = parseFloat(discountPer || 0);

    if (!Number.isFinite(discountPer) || discountPer < 0) {
        discountPer = 0;
    }

    if (discountPer > 100) {
        discountPer = 100;
    }

    let discountAmount = 0;

    document.querySelectorAll('#cart [data-cart-item]').forEach(row => {
        const lineEl = row.querySelector('[data-line-total]');
        const lineSubtotal = lineEl ? parseFloat(lineEl.dataset.lineTotal || 0) : 0;

        if (!lineSubtotal || lineSubtotal <= 0 || discountPer <= 0) {
            row.dataset.appliedDiscountPercent = '0';
            row.dataset.lineDiscount = '0';
            return;
        }

   const lineDiscountInput = row.querySelector('.pos-line-discount-input');
const rowKey = getCartRowKey(row);

let appliedPercent;

if (
    lineDiscountInput &&
    (
        lineDiscountInput.dataset.manualDiscount === '1' ||
        (rowKey && window.__posLineDiscounts[rowKey] !== undefined)
    )
) {
    appliedPercent = normalizePercentValue(
        rowKey && window.__posLineDiscounts[rowKey] !== undefined
            ? window.__posLineDiscounts[rowKey]
            : lineDiscountInput.value
    );
} else {
    const maxPercent = getRowMaxDiscountPercentForSummary(row);
    appliedPercent = maxPercent !== null
        ? Math.min(discountPer, maxPercent)
        : discountPer;
}

const lineDiscount = lineSubtotal * (appliedPercent / 100);

        row.dataset.appliedDiscountPercent = appliedPercent.toFixed(2);
        row.dataset.lineDiscount = lineDiscount.toFixed(2);

        discountAmount += lineDiscount;
    });

    return discountAmount;
}

function setSummaryText(id, value) {
    const el = document.getElementById(id);
    if (el) {
        el.textContent = value;
    }
}

function formatPosMoney(value) {
    value = Number(value || 0);
    if (!Number.isFinite(value)) value = 0;
    return 'Rs ' + Math.round(value).toLocaleString('en-US');
}

function getRowMaxDiscountPercentForSummary(row) {
    if (!row) return null;

    const hasRowMax = String(row.dataset.hasMaxDiscount || '0') === '1';
    const rowMax = parseFloat(row.dataset.maxDiscountPercent || 0);

    if (hasRowMax && Number.isFinite(rowMax) && rowMax > 0) {
        return Math.min(100, Math.max(0, rowMax));
    }

    if (typeof getCartItemMaxDiscountPercent === 'function') {
        return getCartItemMaxDiscountPercent(row);
    }

    return null;
}

function calculateSummaryFromDom() {
    let qty = 0;
    let subtotal = 0;
    let discountAmt = 0;
    let itemWiseTaxAmt = 0;
    let hasItemTax = false;

    const discountInput = document.getElementById('discount');
    const taxInput = document.getElementById('tax');

    let discountPer = normalizePercentValue(discountInput?.value || 0);
    let taxPer = parseFloat(taxInput?.value || 0);

    if (!Number.isFinite(taxPer) || taxPer < 0) {
        taxPer = 0;
    }

    if (typeof isManualTaxOverride !== 'undefined' && !isManualTaxOverride && (!taxPer || taxPer <= 0)) {
        if (typeof detectCartAutoTaxRate === 'function') {
            taxPer = detectCartAutoTaxRate();
        }

        if (typeof setAutoTaxField === 'function') {
            setAutoTaxField(taxPer);
        }
    }

    document.querySelectorAll('#cart [data-cart-item]').forEach(row => {
        const qtyInput = row.querySelector('[data-cart-qty-input]');
        const priceInput = row.querySelector('[data-cart-price-input]');
        const lineTotalEl = row.querySelector('[data-line-total]');
        const lineDiscountInput = row.querySelector('.pos-line-discount-input');
        const rowKey = getCartRowKey(row);

        const rowQty = Math.max(1, parseFloat(qtyInput?.value || row.dataset.qty || 1) || 1);
        const rowPrice = Math.max(0, parseFloat(priceInput?.value || 0) || 0);
        const lineSubtotal = rowQty * rowPrice;

        qty += rowQty;
        subtotal += lineSubtotal;

        let appliedPercent;

        if (
            lineDiscountInput &&
            (
                lineDiscountInput.dataset.manualDiscount === '1' ||
                (rowKey && window.__posLineDiscounts && window.__posLineDiscounts[rowKey] !== undefined)
            )
        ) {
            appliedPercent = normalizePercentValue(
                rowKey && window.__posLineDiscounts && window.__posLineDiscounts[rowKey] !== undefined
                    ? window.__posLineDiscounts[rowKey]
                    : lineDiscountInput.value
            );
        } else {
            const maxPercent = getRowMaxDiscountPercentForSummary(row);
            appliedPercent = maxPercent !== null
                ? Math.min(discountPer, maxPercent)
                : discountPer;
        }

        const lineDiscount = lineSubtotal * (appliedPercent / 100);
        const lineNet = Math.max(0, lineSubtotal - lineDiscount);

        discountAmt += lineDiscount;

        row.dataset.appliedDiscountPercent = appliedPercent.toFixed(2);
        row.dataset.lineDiscount = lineDiscount.toFixed(2);
        row.dataset.lineNetTotal = lineNet.toFixed(2);

        if (lineDiscountInput) {
            lineDiscountInput.value = formatPercentInputValue(appliedPercent);

            if (rowKey && lineDiscountInput.dataset.manualDiscount === '1') {
                window.__posLineDiscounts[rowKey] = appliedPercent;
            }
        }

        if (lineTotalEl) {
            lineTotalEl.dataset.lineTotal = lineNet.toFixed(2);
            lineTotalEl.dataset.lineSubtotal = lineSubtotal.toFixed(2);
            lineTotalEl.dataset.lineQty = rowQty.toFixed(2);
            lineTotalEl.textContent = formatPosMoney(lineNet);
        }

        const discountText = row.querySelector('.pos-line-discount-text');
        if (discountText) {
            discountText.textContent = 'Discounted: ' + formatRefMoney(lineDiscount, 2);
        }

        const rowTaxRate = parseFloat(row.dataset.taxRate || 0) || 0;
        if (rowTaxRate > 0) {
            hasItemTax = true;
            itemWiseTaxAmt += lineNet * (rowTaxRate / 100);
        }
    });

    const taxable = Math.max(0, subtotal - discountAmt);
    const taxAmt = hasItemTax ? itemWiseTaxAmt : (taxable * (taxPer / 100));
    const grand = taxable + taxAmt;

    const cartItemsCountEl = document.getElementById('cartItemsCount');
    if (cartItemsCountEl) {
        cartItemsCountEl.textContent = document.querySelectorAll('#cart [data-cart-item]').length;
    }

    setSummaryText('sumQty', qty.toFixed(0));
    setSummaryText('sumSubtotal', formatPosMoney(subtotal));
    setSummaryText('sumDiscount', formatPosMoney(discountAmt));
    setSummaryText('sumDiscountAmount', formatPosMoney(discountAmt));
    setSummaryText('sumTax', formatPosMoney(taxAmt));
    setSummaryText('sumGrand', formatPosMoney(grand));

    if (typeof updateCustomerChangeReturn === 'function') {
        updateCustomerChangeReturn(grand);
    }
}


const discountEl = document.getElementById('discount');
if (discountEl) {
    discountEl.addEventListener('input', calculateSummaryFromDom);
}

const taxEl = document.getElementById('tax');
if (taxEl) {
    taxEl.addEventListener('input', function () {
        const val = this.value.trim();
        isManualTaxOverride = val !== '';
        calculateSummaryFromDom();
    });
}

document.querySelectorAll('.pay-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.pay-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        selectedPaymentMethod = this.dataset.pay || 'cash';
    });
});

function updateCustomerChangeReturn(grandTotalValue) {
    const customerPayInput = document.getElementById('customerPay');
    const changeReturnInput = document.getElementById('changeReturn');

    if (!customerPayInput || !changeReturnInput) return;

    let grand = Number(grandTotalValue || 0);

    if (!grand || grand <= 0) {
        const grandText = document.getElementById('sumGrand')?.textContent || '0';
        grand = Number(grandText.replace(/[^\d.]/g, '')) || 0;
    }

    const paid = Number(customerPayInput.value || 0);
    const change = Math.max(0, paid - grand);

    changeReturnInput.value = change.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

document.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'customerPay') {
        updateCustomerChangeReturn();
    }
});
/*
|--------------------------------------------------------------------------
| CHECKOUT
|--------------------------------------------------------------------------
| Modes:
| complete = normal invoice
| print    = complete + invoice page print
| hold     = save sale as hold
|--------------------------------------------------------------------------
*/

function buildLineDiscountPayload() {
    const lineDiscounts = [];

    document.querySelectorAll('#cart [data-cart-item]').forEach(row => {
        const qtyInput = row.querySelector('[data-cart-qty-input]');
        const priceInput = row.querySelector('[data-cart-price-input]');
        const discountInput = row.querySelector('.pos-line-discount-input');

        const qty = Math.max(1, parseFloat(qtyInput?.value || row.dataset.qty || 1) || 1);
        const price = Math.max(0, parseFloat(priceInput?.value || 0) || 0);
        const discountPercent = normalizePercentValue(
            discountInput ? discountInput.value : row.dataset.appliedDiscountPercent
        );

        const lineSubtotal = qty * price;
        const discountAmount = lineSubtotal * (discountPercent / 100);
        const lineTotalAfterDiscount = Math.max(0, lineSubtotal - discountAmount);

        lineDiscounts.push({
            cart_key: getCartRowKey(row),
            item_id: row.dataset.sourceId || row.dataset.itemId || row.dataset.productId || '',
            name: row.dataset.name || '',
            qty: Number(qty.toFixed(2)),
            price: Number(price.toFixed(2)),
            discount_percent: Number(discountPercent.toFixed(2)),
            discount_amount: Number(discountAmount.toFixed(2)),
            line_subtotal: Number(lineSubtotal.toFixed(2)),
            line_total_after_discount: Number(lineTotalAfterDiscount.toFixed(2))
        });
    });

    return lineDiscounts;
}

function checkout() {
    if (isWholesaleMode(selectedType) && !hasWholesaleCustomer) {
        alert('Wholesale checkout is not available for this client.');
        selectedType = 'Retailer';
        selectedCustomerId = '';
        selectedCustomerName = getDefaultCustomerName(selectedType);
        selectedCustomerPhone = '';
        retailerCustomerInfoPromptShown = false;
        updateSelectedCustomerBox();
        updateProductDisplayedPrices();
        return;
    }

    const cartItemsCount = document.querySelectorAll('#cart [data-cart-item]').length;
    if (cartItemsCount <= 0) {
        alert('Cart is empty.');
        if (typeof focusProductSearchShortcut === 'function') {
            focusProductSearchShortcut();
        }
        return;
    }

    const discountEl = document.getElementById('discount');
    const taxEl = document.getElementById('tax');

    const discount = discountEl ? (discountEl.value || 0) : 0;
    const tax = taxEl ? (taxEl.value || detectCartAutoTaxRate()) : detectCartAutoTaxRate();
    const checkoutType = getCheckoutCustomerType();
    const checkoutCustomerId = selectedCustomerId || 0;
    
    updateReferenceLineDiscountPreview();
calculateSummaryFromDom();

const lineDiscounts = buildLineDiscountPayload();

    const posMode = window.__posCheckoutMode || 'complete';
    const isHoldMode = posMode === 'hold';
    const shouldPrintAfterCheckout = posMode === 'print';
    const ajaxAction = isHoldMode ? 'save_hold' : 'checkout';

    /*
    |--------------------------------------------------------------------------
    | Retailer / Walk-In customer info prompt
    |--------------------------------------------------------------------------
    | Hold Sale par popup nahi aayega.
    | Complete / Print par pehli dafa customer info popup aayega.
    |--------------------------------------------------------------------------
    */
    if (!isHoldMode && selectedType === 'Retailer' && !selectedCustomerId && !retailerCustomerInfoPromptShown) {
        const hasCartItems = document.querySelectorAll('#cart [data-cart-item]').length > 0;

        if (hasCartItems) {
            pendingRetailerCheckoutAfterCustomerInfo = true;
            openRetailerCustomerInfoPopup(true);
            return;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Wholesale customer required
    |--------------------------------------------------------------------------
    */
    if (isWholesaleMode(selectedType) && !selectedCustomerId) {
        alert('Please select wholesale customer');
        window.__posCheckoutMode = '';
        return;
    }

    fetch('ajax_add_to_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body:
            `action=${encodeURIComponent(ajaxAction)}` +
            `&customer_id=${encodeURIComponent(checkoutCustomerId)}` +
            `&type=${encodeURIComponent(checkoutType)}` +
            `&customer_name=${encodeURIComponent(selectedCustomerName || '')}` +
            `&customer_phone=${encodeURIComponent(selectedCustomerPhone || '')}` +
          `&discount=${encodeURIComponent(discount)}` +
`&line_discounts=${encodeURIComponent(JSON.stringify(lineDiscounts))}` +
`&tax=${encodeURIComponent(tax)}` +
`&payment_method=${encodeURIComponent(selectedPaymentMethod)}`
            
    })
    .then(handleAjaxJsonSession)
    .then(res => {
        if (res === null) return;

        if (res.status === 'success') {
            if (isHoldMode) {
                window.__posCheckoutMode = '';
                retailerCustomerInfoPromptShown = false;
                pendingRetailerCheckoutAfterCustomerInfo = false;

                if (typeof loadCart === 'function') {
                    loadCart();
                }

                if (typeof calculateSummaryFromDom === 'function') {
                    setTimeout(calculateSummaryFromDom, 150);
                }

                alert(res.message || 'Sale saved on hold successfully');
                return;
            }

        if (res.sale_id && Number(res.sale_id) > 0) {
    window.__posCheckoutMode = '';

    // Direct bill / invoice generate page
    let redirectUrl = 'thermal.php?sale_id=' + encodeURIComponent(res.sale_id);

    // Agar Print mode se checkout hua hai to auto print bhi trigger hoga
    if (shouldPrintAfterCheckout) {
        redirectUrl += '&auto_print=1';
    }

    window.location.href = redirectUrl;
} else {
    window.__posCheckoutMode = '';
    alert('Order saved, but sale_id not found in response.');
    console.log(res);
}
        } else {
            window.__posCheckoutMode = '';
            alert(res.message || 'Checkout failed');
        }
    })
    .catch((error) => {
        window.__posCheckoutMode = '';
        console.error('Checkout error:', error);
        alert('Checkout request failed. Check console/backend response.');
    });
}
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('dashboardSidebar');
    if (!sidebar) return;

    const clickable = sidebar.querySelectorAll('a, button');
    const matches = [];

    clickable.forEach(function (el) {
        const txt = (el.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        if (txt === 'new customer' || txt.includes('new customer')) {
            matches.push(el);
        }
    });

    if (matches.length > 1) {
        matches.forEach(function (el, index) {
            if (index > 0) {
                const parent = el.closest('li, .nav-item, .menu-item, .dropdown-item, .d-grid, .btn-group');
                if (parent) {
                    parent.style.display = 'none';
                } else {
                    el.style.display = 'none';
                }
            }
        });
    }
});

function escapeHtml(str) {
    return String(str)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

['customProductName', 'customProductPrice', 'customProductQty'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addCustomProduct();
            }
        });
    }
});

updateProductDisplayedPrices();
loadCart();
updateWalkInCartScroller();
setAutoTaxField(defaultProductTaxRate);



function toggleCardSku(btn) {
    const wrap = btn.closest('.item-sku-wrap');
    if (!wrap) return;

    const sku = wrap.querySelector('.item-sku');
    if (!sku) return;

    const skuValue = sku.textContent.trim();

    wrap.classList.toggle('sku-open');

    if (wrap.classList.contains('sku-open')) {
        btn.textContent = skuValue || 'SKU';
        btn.title = 'Click to hide SKU';
    } else {
        btn.textContent = 'SKU';
        btn.title = skuValue ? 'Show SKU: ' + skuValue : 'Show SKU';
    }
}


/* POS MOBILE SIDEBAR TOGGLE */
document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;
    const sidebar = document.getElementById('dashboardSidebar');

    const toggleSelectors = [
        '#sidebarToggle',
        '#toggleSidebar',
        '.sidebar-toggle',
        '.sidebar-toggler',
        '.topbar-menu-toggle',
        '.hamburger-btn',
        '[data-sidebar-toggle]',
        '.topbar .bi-list'
    ];

    let toggleBtn = null;

    for (const selector of toggleSelectors) {
        toggleBtn = document.querySelector(selector);
        if (toggleBtn) break;
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            if (window.innerWidth <= 991) {
                e.preventDefault();
                body.classList.toggle('sidebar-open');
                body.classList.toggle('dashboard-mobile-sidebar-open');
            }
        });
    }

    document.addEventListener('click', function (e) {
        if (window.innerWidth > 991) return;

        const isToggle = e.target.closest('#sidebarToggle, #toggleSidebar, .sidebar-toggle, .sidebar-toggler, .topbar-menu-toggle, .hamburger-btn, [data-sidebar-toggle], .topbar .bi-list');

        if (
            body.classList.contains('sidebar-open') &&
            sidebar &&
            !sidebar.contains(e.target) &&
            !isToggle
        ) {
            body.classList.remove('sidebar-open');
            body.classList.remove('dashboard-mobile-sidebar-open');
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            body.classList.remove('sidebar-open');
            body.classList.remove('dashboard-mobile-sidebar-open');
        }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 991) {
            body.classList.remove('sidebar-open');
            body.classList.remove('dashboard-mobile-sidebar-open');
        }
    });
});


function initCartPreviewInvoiceHeader() {
    const invoiceNoEl = document.getElementById('cartPreviewInvoiceNo');
    const dateTimeEl = document.getElementById('cartPreviewDateTime');

    if (!invoiceNoEl || !dateTimeEl) return;

    if (!invoiceNoEl.dataset.generated) {
        const now = new Date();
        const previewNo = String(Math.floor(now.getTime() / 1000)).slice(-6);
        invoiceNoEl.textContent = 'INV-' + previewNo;
        invoiceNoEl.dataset.generated = '1';
    }

    function updateCartHeaderDateTime() {
        const now = new Date();

        const datePart = now.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });

        const timePart = now.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        });

        dateTimeEl.textContent = datePart + ' | ' + timePart;
    }

    updateCartHeaderDateTime();
    setInterval(updateCartHeaderDateTime, 30000);
}

document.addEventListener('DOMContentLoaded', initCartPreviewInvoiceHeader);
initCartPreviewInvoiceHeader();

/* =========================================================
   POS BARCODE SCANNER AJAX
   Shortcut keys ko bilkul touch nahi karta.
   F3 / Scan Barcode button productSearch focus karega.
   Scanner barcode type karega + Enter bhejega.
   Enter par ajax_add_to_cart.php me scan_barcode action chalega.
========================================================= */
(function () {
    if (window.__POS_BARCODE_SCANNER_BOUND__) return;
    window.__POS_BARCODE_SCANNER_BOUND__ = true;

    const POS_SCAN_AJAX_URL = 'ajax_add_to_cart.php';
    let barcodeScanBusy = false;

    function normalizeBarcode(value) {
        return String(value || '')
            .replace(/[\r\n\t]+/g, '')
            .trim();
    }

    function showBarcodeMessage(message) {
        let toast = document.getElementById('posShortcutToast');

        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'posShortcutToast';
            toast.style.cssText = [
                'position:fixed',
                'right:18px',
                'bottom:18px',
                'z-index:999999',
                'min-width:220px',
                'max-width:360px',
                'padding:12px 14px',
                'border-radius:10px',
                'background:#0b1220',
                'color:#fff',
                'border:1px solid rgba(139,92,246,.45)',
                'box-shadow:0 18px 40px rgba(0,0,0,.35)',
                'font-size:13px',
                'font-weight:800',
                'display:none'
            ].join(';');

            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.style.display = 'block';

        clearTimeout(window.__posShortcutToastTimer);
        window.__posShortcutToastTimer = setTimeout(function () {
            toast.style.display = 'none';
        }, 1800);
    }

    function getScannerInput() {
        return document.getElementById('productSearch') || document.getElementById('cartSearch');
    }

    function getScanTierMeta() {
        if (typeof getPriceTierMetaForSelectedType === 'function') {
            return getPriceTierMetaForSelectedType();
        }

        return {
            tierType: 'default',
            tierNo: 0,
            tierLabel: 'Default'
        };
    }

    function getScanCustomerType() {
        if (typeof getCheckoutCustomerType === 'function') {
            return getCheckoutCustomerType();
        }

        if (typeof selectedType !== 'undefined' && selectedType) {
            return selectedType;
        }

        if (window.selectedType) {
            return window.selectedType;
        }

        return 'Retailer';
    }

    async function sendScannedBarcodeToCart(barcode) {
        barcode = normalizeBarcode(barcode);

        if (!barcode) {
            return;
        }

        if (barcode.length < 3) {
            showBarcodeMessage('Invalid barcode.');
            return;
        }

        if (barcodeScanBusy) {
            return;
        }

        barcodeScanBusy = true;

        const tierMeta = getScanTierMeta();

        const body = new URLSearchParams();
        body.set('action', 'scan_barcode');
        body.set('barcode', barcode);

        /*
        |--------------------------------------------------------------------------
        | Existing wholesale/customer pricing support
        |--------------------------------------------------------------------------
        | Ye values existing POS logic se read hongi.
        | Shortcut / checkout / cart logic change nahi ho raha.
        |--------------------------------------------------------------------------
        */
        body.set('customer_type', getScanCustomerType());
        body.set('selected_type', getScanCustomerType());
        body.set('price_tier_type', tierMeta.tierType || 'default');
        body.set('price_tier_no', tierMeta.tierNo || 0);
        body.set('price_tier_label', tierMeta.tierLabel || 'Default');

        try {
            const response = await fetch(POS_SCAN_AJAX_URL, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body.toString()
            });

            let html = '';

            if (typeof handleAjaxSession === 'function') {
                html = await handleAjaxSession(response);
                if (html === null) {
                    barcodeScanBusy = false;
                    return;
                }
            } else {
                html = await response.text();
            }

            if (!response.ok) {
                showBarcodeMessage((html || 'Barcode product not found.').trim());
                barcodeScanBusy = false;
                focusScannerInputAgain();
                return;
            }

            if (typeof refreshCartHtml === 'function') {
                refreshCartHtml(html);
            } else {
                const cart = document.getElementById('cart');
                if (cart) {
                    cart.innerHTML = html;
                }

                if (typeof calculateSummaryFromDom === 'function') {
                    calculateSummaryFromDom();
                }
            }

            showBarcodeMessage('Barcode item added.');

            barcodeScanBusy = false;
            focusScannerInputAgain();
        } catch (err) {
            console.error(err);
            barcodeScanBusy = false;
            showBarcodeMessage('Barcode scan request failed.');
            focusScannerInputAgain();
        }
    }

    function focusScannerInputAgain() {
        const input = getScannerInput();

        if (!input) {
            return;
        }

        setTimeout(function () {
            input.value = '';
            input.focus();

            if (typeof input.select === 'function') {
                input.select();
            }
        }, 80);
    }

    function bindScannerInput(input) {
        if (!input || input.dataset.barcodeScannerBound === '1') {
            return;
        }

        input.dataset.barcodeScannerBound = '1';
        input.setAttribute('autocomplete', 'off');

        input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') {
                return;
            }

            const barcode = normalizeBarcode(input.value);

            if (!barcode) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            input.value = '';
            sendScannedBarcodeToCart(barcode);
        });
    }

    function initBarcodeScanner() {
        bindScannerInput(document.getElementById('productSearch'));
        bindScannerInput(document.getElementById('cartSearch'));
    }

    document.addEventListener('DOMContentLoaded', initBarcodeScanner);
    initBarcodeScanner();

    /*
    |--------------------------------------------------------------------------
    | Manual testing ke liye:
    | Console me posScanBarcodeToCart('123456') run kar sakte ho.
    |--------------------------------------------------------------------------
    */
    window.posScanBarcodeToCart = sendScannedBarcodeToCart;
})();


/* =========================================================
   POS REAL WORKING SHORTCUTS
   F3 Scan/Search | F5 Print Sale | F6 Hold Sale | F9 Clear Cart | F12 Complete Sale
========================================================= */
(function () {
    if (window.__POS_SHORTCUTS_BOUND__) return;
    window.__POS_SHORTCUTS_BOUND__ = true;

    window.__posCheckoutMode = window.__posCheckoutMode || '';

    function normalizeShortcutKey(e) {
        const codeMap = {
            114: 'F3',
            116: 'F5',
            117: 'F6',
            120: 'F9',
            123: 'F12'
        };

        const code = e.keyCode || e.which;
        if (codeMap[code]) return codeMap[code];

        const key = String(e.key || e.code || '').toUpperCase();
        if (['F3', 'F5', 'F6', 'F9', 'F12'].includes(key)) return key;

        return '';
    }

    function getCartItemsCountForShortcut() {
        return document.querySelectorAll('#cart [data-cart-item]').length;
    }

    function showShortcutMessage(message) {
        let toast = document.getElementById('posShortcutToast');

        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'posShortcutToast';
            toast.style.cssText = [
                'position:fixed',
                'right:18px',
                'bottom:18px',
                'z-index:999999',
                'min-width:220px',
                'max-width:360px',
                'padding:12px 14px',
                'border-radius:10px',
                'background:#0b1220',
                'color:#fff',
                'border:1px solid rgba(139,92,246,.45)',
                'box-shadow:0 18px 40px rgba(0,0,0,.35)',
                'font-size:13px',
                'font-weight:800',
                'display:none'
            ].join(';');
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.style.display = 'block';

        clearTimeout(window.__posShortcutToastTimer);
        window.__posShortcutToastTimer = setTimeout(function () {
            toast.style.display = 'none';
        }, 1800);
    }

    window.focusProductSearchShortcut = function () {
        const search = document.getElementById('productSearch') || document.querySelector('.search-input');

        if (!search) {
            showShortcutMessage('Product search field not found.');
            return;
        }

        search.focus();
        if (typeof search.select === 'function') {
            search.select();
        }

        showShortcutMessage('Scan/Search ready.');
    };

    window.clearCartShortcut = function () {
        if (typeof clearAllCartItems === 'function') {
            clearAllCartItems();
            return;
        }

        showShortcutMessage('Clear cart function not found.');
    };

    window.holdSaleShortcut = function () {
        if (getCartItemsCountForShortcut() <= 0) {
            showShortcutMessage('Cart is empty.');
            focusProductSearchShortcut();
            return;
        }

        if (!confirm('Hold current sale?')) {
            return;
        }

        window.__posCheckoutMode = 'hold';

        if (typeof checkout === 'function') {
            checkout();
            return;
        }

        showShortcutMessage('Checkout function not found.');
    };

    window.completeSaleShortcut = function () {
        if (getCartItemsCountForShortcut() <= 0) {
            showShortcutMessage('Cart is empty.');
            focusProductSearchShortcut();
            return;
        }

        window.__posCheckoutMode = 'complete';

        if (typeof checkout === 'function') {
            checkout();
            return;
        }

        showShortcutMessage('Checkout function not found.');
    };

    window.printCurrentSaleShortcut = function () {
        if (getCartItemsCountForShortcut() <= 0) {
            showShortcutMessage('Cart is empty.');
            focusProductSearchShortcut();
            return;
        }

        window.__posCheckoutMode = 'print';

        if (typeof checkout === 'function') {
            checkout();
            return;
        }

        showShortcutMessage('Checkout function not found.');
    };

    function runPosShortcut(e) {
        const key = normalizeShortcutKey(e);
        if (!key) return;

        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        if (key === 'F3') {
            focusProductSearchShortcut();
            return;
        }
/*
|--------------------------------------------------------------------------
| F5 Print Shortcut Disabled
|--------------------------------------------------------------------------
| Print action UI se remove ho chuka hai.
| preventDefault pehle execute hota hai, isliye F5 page refresh bhi nahi karega.
|--------------------------------------------------------------------------
*/
if (key === 'F5') {
    return;
}

        if (key === 'F6') {
            holdSaleShortcut();
            return;
        }

        if (key === 'F9') {
            clearCartShortcut();
            return;
        }

        if (key === 'F12') {
            completeSaleShortcut();
            return;
        }
    }

    window.addEventListener('keydown', runPosShortcut, true);
    document.addEventListener('keydown', runPosShortcut, true);
})();

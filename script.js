document.addEventListener('DOMContentLoaded', () => {

    let isUserLoggedIn = false;

    const ui = {
        pages: document.querySelectorAll('.page-content'),
        loggedInView: document.querySelector('.logged-in-view'),
        loggedOutView: document.querySelector('.logged-out-view'),
        logoutButton: document.getElementById('btn-logout'),
        signupForm: document.getElementById('signup-form'),
        loginForm: document.getElementById('login-form'),
        signupMessage: document.getElementById('form-message'),
        loginMessage: document.getElementById('login-message'),
        profileWelcome: document.getElementById('profile-welcome'),
        orderHistoryBody: document.querySelector('#order-history-table tbody'),
        cartToggleButton: document.querySelector('.fa-cart-shopping'),
        cartOverlay: document.querySelector('.cart-overlay'),
        cartElement: document.querySelector('.cart'),
        cartCloseButton: document.querySelector('.cart-close'),
        cartBody: document.querySelector('.cart-body'),
        cartTotalElement: document.querySelector('.cart-total'),
        cartClearButton: document.querySelector('.cart-clear'),
        checkoutButton: document.querySelector('.checkout'),
    };

    async function apiRequest(action, data = {}, method = 'POST') {
        const options = { method, headers: { 'Content-Type': 'application/json' } };
        if (method === 'POST') options.body = JSON.stringify({ action, ...data });
        try {
            const response = await fetch(`api.php${method === 'GET' ? `?action=${action}` : ''}`, options);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            return await response.json();
        } catch (error) {
            console.error('API Request Error:', error);
            return { success: false, message: 'A network error occurred.' };
        }
    }

    function showPage(pageId) {
        const targetId = pageId || 'home';
        ui.pages.forEach(page => page.classList.remove('active'));
        const targetPage = document.getElementById(targetId);
        (targetPage || document.getElementById('home')).classList.add('active');
        window.scrollTo(0, 0);
        if (targetId === 'profile') loadProfileData();
    }

    function updateLoginState(isLoggedIn, userData = {}) {
        isUserLoggedIn = isLoggedIn; // Update global flag
        ui.loggedInView.style.display = isLoggedIn ? 'flex' : 'none';
        ui.loggedOutView.style.display = isLoggedIn ? 'none' : 'flex';
        if (isLoggedIn) ui.profileWelcome.textContent = `Welcome, ${userData.name}!`;
    }

    async function checkSession() {
        const result = await apiRequest('check_session', {}, 'GET');
        updateLoginState(result.loggedIn, result.user);
        await cart.sync();
    }

    async function loadProfileData() {
        const result = await apiRequest('get_profile_data', {}, 'GET');
        if (result.success) {
            updateLoginState(true, result.user);
            let html = result.orders.length > 0 ? '' : '<tr><td colspan="5" class="text-center">You have no past orders.</td></tr>';
            result.orders?.forEach(order => {
                html += `<tr>
                    <td>${order.order_group_id.substring(6, 12).toUpperCase()}</td>
                    <td>${new Date(order.ordered_at).toLocaleDateString()}</td>
                    <td>${order.product_name}</td>
                    <td>${order.quantity}</td>
                    <td>Tk.${order.price_per_item}</td>
                </tr>`;
            });
            ui.orderHistoryBody.innerHTML = html;
        } else { showPage('login'); }
    }

    const cart = {
        render(items = []) {
            ui.cartBody.innerHTML = items.length === 0 ? '<div class="cart-empty">Your cart is empty.</div>' :
                items.map(item => `
                    <div class="cart-item" data-product-id="${item.product_id}">
                        <div class="cart-item-detail"><h3>${item.product_name}</h3><div class="cart-item-amount"><i class="fa-solid fa-minus qty-change" data-change="-1"></i><span>${item.quantity}</span><i class="fa-solid fa-plus qty-change" data-change="1"></i></div></div>
                        <div class="cart-item-price">Tk.${(item.price * item.quantity).toFixed(2)}</div>
                    </div>`).join('');
            const total = items.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            ui.cartTotalElement.textContent = `Tk.${total.toFixed(2)}`;
        },
        async sync() {
            const result = await apiRequest('get_cart', {}, 'GET');
            if (result.success) this.render(result.cart);
        },
        async addToCart(itemData) {
            if (!isUserLoggedIn) {
                alert("Please log in to add items to your cart.");
                showPage('login');
                return;
            }
            const result = await apiRequest('add_to_cart', { item: itemData });
            if (result.success) { this.sync(); this.open(); } 
            else { alert(result.message); }
        },
        async updateQuantity(productId, change) { await apiRequest('update_cart_quantity', { productId, change }); this.sync(); },
        async clear() { await apiRequest('clear_cart'); this.sync(); },
        async checkout() {
            if (!isUserLoggedIn) {
                alert("Please log in to check out.");
                showPage('login');
                return;
            }
            const result = await apiRequest('checkout');
            alert(result.message);
            if (result.success) { this.sync(); this.close(); }
        },
        open() { ui.cartElement.classList.add('show'); ui.cartOverlay.classList.add('show'); },
        close() { ui.cartElement.classList.remove('show'); ui.cartOverlay.classList.remove('show'); }
    };

    document.body.addEventListener('click', (event) => {
        const link = event.target.closest('a[href^="#"]');
        if (link) { event.preventDefault(); showPage(link.getAttribute('href').substring(1)); }
        if (event.target.classList.contains('add-to-cart-btn')) cart.addToCart(event.target.dataset);
    });
    ui.loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const result = await apiRequest('login', Object.fromEntries(new FormData(ui.loginForm).entries()));
        ui.loginMessage.textContent = result.message;
        ui.loginMessage.className = result.success ? 'success' : 'error';
        if (result.success) { updateLoginState(true, result.user); showPage('profile'); await cart.sync(); }
    });
    ui.signupForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const result = await apiRequest('signup', Object.fromEntries(new FormData(ui.signupForm).entries()));
        ui.signupMessage.textContent = result.message;
        ui.signupMessage.className = result.success ? 'success' : 'error';
        if (result.success) ui.signupForm.reset();
    });
    ui.logoutButton.addEventListener('click', async () => {
        await apiRequest('logout');
        updateLoginState(false);
        showPage('home');
        await cart.sync();
    });
    ui.cartToggleButton.addEventListener('click', () => cart.open());
    ui.cartCloseButton.addEventListener('click', () => cart.close());
    ui.cartOverlay.addEventListener('click', () => cart.close());
    ui.cartClearButton.addEventListener('click', () => cart.clear());
    ui.checkoutButton.addEventListener('click', () => cart.checkout());
    ui.cartBody.addEventListener('click', (e) => {
        if (e.target.classList.contains('qty-change')) {
            const productId = e.target.closest('.cart-item').dataset.productId;
            cart.updateQuantity(productId, parseInt(e.target.dataset.change));
        }
    });

    showPage(window.location.hash.substring(1) || 'home');
    checkSession();
});
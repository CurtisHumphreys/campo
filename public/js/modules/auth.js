import * as API from '../api.js';
import { navigateTo } from '../app.js';

export function renderLogin(container) {
    container.innerHTML = `
        <div class="login-container">
            <h1>Campo Login</h1>
            <form id="login-form">
                <div class="form-group">
                    <input type="text" id="username" placeholder="Username" required>
                </div>
                <div class="form-group">
                    <input type="password" id="password" placeholder="Password" required>
                </div>
                <button type="submit">Login</button>
            </form>
        </div>
    `;

    document.getElementById('login-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('username').value;
        const password = document.getElementById('password').value;

        try {
            const result = await API.post('/login', { username, password });
            if (result.success) {
                document.getElementById('sidebar').classList.remove('hidden');
                navigateTo('/dashboard');
            }
        } catch (err) {
            alert('Login failed: ' + err.message);
        }
    });
}

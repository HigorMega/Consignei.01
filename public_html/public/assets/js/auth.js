let emailTentativa = "";

    // --- LÓGICA DE COOKIES ---
    function checarCookies() {
        if (!localStorage.getItem('consignei_cookies_accepted')) {
            setTimeout(() => {
                document.getElementById('cookieBanner').classList.add('show');
            }, 1000);
        }
    }

    function aceitarCookies() {
        localStorage.setItem('consignei_cookies_accepted', 'true');
        document.getElementById('cookieBanner').classList.remove('show');
    }
    
    window.onload = checarCookies;

    // --- FUNÇÕES DE LOGIN ---
    function toggleSenha(inputId, icon) {
        const input = document.getElementById(inputId);
        if (input.type === "password") {
            input.type = "text";
            icon.classList.remove('ph-eye-slash');
            icon.classList.add('ph-eye');
        } else {
            input.type = "password";
            icon.classList.remove('ph-eye');
            icon.classList.add('ph-eye-slash');
        }
    }

    document.getElementById('btnReenviarEmail').addEventListener('click', reenviarEmailAtivacao);

    document.getElementById('formLogin').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const btn = document.getElementById('btnEntrar');
        const originalText = btn.innerText;
        const email = document.getElementById('email').value;
        const senha = document.getElementById('senha').value;
        
        emailTentativa = email;

        document.getElementById('btnReenviarEmail').style.display = 'none';
        document.getElementById('btnFecharErro').style.display = 'inline-block';
        document.getElementById('btnFecharErro').innerText = "Tentar Novamente";
        
        btn.innerText = "Verificando...";
        btn.disabled = true;
        btn.style.opacity = "0.7";

        try {
            const response = await fetch('../api/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: email, senha: senha })
            });

            const rawText = await response.text();
            let result = null;

            try {
                result = JSON.parse(rawText);
            } catch (parseError) {
                console.error('Resposta não-JSON:', rawText);
                const statusInfo = response.ok ? "Resposta inválida" : `HTTP ${response.status}`;
                const snippet = rawText.trim().replace(/\s+/g, ' ').slice(0, 200);
                const detalhe = snippet ? `\n\nDetalhe: ${snippet}` : "";
                document.getElementById('modalErroDesc').innerText =
                    `Erro técnico no servidor (${statusInfo}). Verifique o arquivo de login ou a conexão com o banco.${detalhe}`;
                openModal('modalErro');
                btn.innerText = originalText; btn.disabled = false; btn.style.opacity = "1";
                return;
            }

            if (result.success) {
                openModal('modalSucesso');
                setTimeout(() => {
                    window.location.href = 'dashboard';
                }, 1500);
            } else {
                const msg = result.message || result.error || "Dados incorretos.";
                document.getElementById('modalErroDesc').innerText = msg;
                
                if (msg.toLowerCase().includes("confirme") || msg.toLowerCase().includes("ativar")) {
                    document.getElementById('btnReenviarEmail').style.display = 'inline-block';
                    document.getElementById('btnFecharErro').innerText = "Fechar";
                }

                openModal('modalErro');
                btn.innerText = originalText; btn.disabled = false; btn.style.opacity = "1";
            }

        } catch (error) {
            console.error('Erro:', error);
            const statusMsg = error && error.message ? error.message : "Erro de conexão com o servidor.";
            document.getElementById('modalErroDesc').innerText = statusMsg;
            openModal('modalErro');
            btn.innerText = originalText; btn.disabled = false; btn.style.opacity = "1";
        }
    });

    async function reenviarEmailAtivacao() {
        const btn = document.getElementById('btnReenviarEmail');
        btn.innerText = "Enviando...";
        btn.disabled = true;

        try {
            const res = await fetch('../api/reenviar_email.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: emailTentativa })
            });
            const json = await res.json();
            document.getElementById('modalErroDesc').innerText = json.message;
            btn.style.display = 'none';
        } catch (e) {
            document.getElementById('modalErroDesc').innerText = "Erro ao tentar reenviar.";
            openModal('modalErro');
            btn.innerText = "Tentar Reenviar";
            btn.disabled = false;
        }
    }

document.getElementById('formLogin').addEventListener('submit', async (e) => {
    e.preventDefault();

    const email = document.getElementById('email').value;
    const senha = document.getElementById('senha').value;
    const btn = document.querySelector('button');

    // Feedback visual
    const textoOriginal = btn.innerText;
    btn.innerText = "Verificando...";
    btn.disabled = true;

    try {
        // Tenta conectar com a API de login
        const res = await fetch('../api/login.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ email, senha })
        });

        // 1. Pega a resposta como TEXTO primeiro (para ver se veio erro HTML ou PHP)
        const text = await res.text(); 
        console.log("Resposta do servidor:", text); // Para quem sabe olhar o console (F12)

        try {
            // 2. Tenta transformar esse texto em JSON (Dados do sistema)
            const data = JSON.parse(text);

            if (data.success) {
                // SUCESSO!
                alert("Login Aprovado! Redirecionando...");
                window.location.href = "dashboard";
            } else {
                // O servidor respondeu, mas recusou o login (ex: senha errada ou erro de banco)
                alert("Atenção: " + (data.message || "Erro desconhecido"));
            }
        } catch (jsonError) {
            // 3. SE CAIR AQUI, é porque o servidor devolveu um ERRO (HTML) e não os dados (JSON)
            // Isso geralmente acontece se o caminho do banco estiver errado ou tiver erro de digitação no PHP
            alert("ERRO TÉCNICO NO SERVIDOR:\n\n" + text.substring(0, 300) + "\n...");
        }

    } catch (err) {
        // Erro de internet ou arquivo não encontrado (404)
        console.error(err);
        alert("Erro de Conexão: O arquivo '../api/login.php' não foi encontrado ou a internet falhou.");
    } finally {
        btn.innerText = textoOriginal;
        btn.disabled = false;
    }
});

function abrirModalExcluirConta() { document.getElementById('inputConfirmaExclusao').value = ''; openModal('modalExcluirConta'); }
function fecharModalExcluirConta() { closeModal('modalExcluirConta'); }
async function executarExclusaoDefinitiva() {
    const input = document.getElementById('inputConfirmaExclusao').value.trim().toUpperCase();
    const btn = document.getElementById('btnFinalizarExclusao');
    if (input !== "EXCLUIR") {
        Swal.fire({ icon: 'error', title: 'Atenção', text: 'Digite EXCLUIR corretamente.' });
        return;
    }
    btn.innerText = "Excluindo..."; btn.disabled = true;
    try {
        const response = await fetch('../api/excluir_conta.php', { method: 'POST' });
        const result = await response.json();
        if (result.success) {
            fecharModalExcluirConta();
            document.getElementById('msgSucesso').innerText = "Conta removida com sucesso!";
            openModal('modalSucesso');
            setTimeout(() => { window.location.href = "/"; }, 2000);
        } else {
            Swal.fire({ icon: 'error', title: 'Erro', text: result.error || 'Erro ao excluir.' });
            btn.innerText = "Apagar Tudo"; btn.disabled = false;
        }
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Erro', text: 'Erro de conexão.' });
        btn.innerText = "Apagar Tudo"; btn.disabled = false;
    }
}

document.getElementById('btnAbrirExcluirConta').addEventListener('click', abrirModalExcluirConta);
document.getElementById('btnFinalizarExclusao').addEventListener('click', executarExclusaoDefinitiva);

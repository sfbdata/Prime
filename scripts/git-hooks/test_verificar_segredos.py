"""Testes da guarda de segredos (scripts/git-hooks/verificar-segredos.py).

Rodar:  python3 -m unittest discover -s scripts/git-hooks -p 'test_*.py' -v

Nenhum segredo de teste está escrito por extenso neste arquivo: todos são montados em tempo de
execução (concatenação + aleatório). Do contrário este arquivo seria, ele mesmo, um achado — da
própria guarda e do gitleaks no CI.
"""

from __future__ import annotations

import importlib.util
import os
import random
import string
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stderr
from io import StringIO
from pathlib import Path

AQUI = Path(__file__).resolve().parent
SCRIPT = AQUI / 'verificar-segredos.py'

_spec = importlib.util.spec_from_file_location('verificar_segredos', SCRIPT)
guarda = importlib.util.module_from_spec(_spec)
sys.modules['verificar_segredos'] = guarda
_spec.loader.exec_module(guarda)

_rng = random.Random(20261012)


def aleatorio(n: int, alfabeto: str = string.ascii_letters + string.digits) -> str:
    # Sem sequências (0123456, abcdefg) que a guarda trata como fictícias de propósito.
    while True:
        v = ''.join(_rng.choice(alfabeto) for _ in range(n))
        if not guarda.eh_placeholder(v):
            return v


def senha_de_app_gmail() -> str:
    return aleatorio(16, string.ascii_lowercase)


def regras(arquivo: str, linha: str) -> list[str]:
    return [regra for regra, _ in guarda.verificar_linha(arquivo, linha)]


class ConteudoTest(unittest.TestCase):
    def test_dsn_do_vazamento_real_com_arroba_no_usuario_e_pego(self):
        # O formato exato do que vazou: e-mail SEM %40 no usuário. A 1ª versão da regex deixava passar.
        linha = 'MAILER_DSN=smtp://' + 'conta' + '@gmail.com:' + senha_de_app_gmail() + '@smtp.gmail.com:587'
        self.assertEqual(['url-com-credencial'], regras('app/.env', linha))

    def test_dsn_com_usuario_codificado_e_pego(self):
        linha = 'MAILER_DSN=smtp://conta%40gmail.com:' + senha_de_app_gmail() + '@smtp.gmail.com:587'
        self.assertEqual(['url-com-credencial'], regras('app/.env', linha))

    def test_dsn_em_comentario_tambem_e_pego(self):
        # A linha 14 do app/.env antigo era um DSN real COMENTADO.
        linha = '### MAILER_DSN=smtp://conta%40gmail.com:' + senha_de_app_gmail() + '@smtp.gmail.com:587 ###'
        self.assertEqual(['url-com-credencial'], regras('app/.env', linha))

    def test_dsn_em_codigo_fonte_tambem_e_pego(self):
        linha = "$dsn = 'redis://admin:" + aleatorio(20) + "@cache:6379';"
        self.assertEqual(['url-com-credencial'], regras('app/src/Algo.php', linha))

    def test_banco_de_dev_do_compose_e_aceito(self):
        self.assertEqual([], regras('app/.env', 'DATABASE_URL="pgsql://symfony:symfony@db:5432/saas"'))

    def test_placeholders_sao_aceitos(self):
        for linha in (
            '# MAILER_DSN=smtp://usuario%40gmail.com:senha_app@smtp.gmail.com:587',
            'DATABASE_URL=pgsql://u:p@db:5432/db',
            'DATABASE_URL=pgsql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@db:5432/saas',
            'MAILER_DSN=null://null',
            'POSTGRES_PASSWORD=troque_esta_senha_forte',
            'E2E_USER_PASSWORD=e2e_local_placeholder',
            "    mailer_from: '%env(MAILER_FROM)%'",
            "    App\\Auth\\Service\\RedefinicaoSenhaMailerInterface: '@App\\Auth\\Service\\RedefinicaoSenhaMailer'",
        ):
            with self.subTest(linha=linha):
                self.assertEqual([], regras('app/.env', linha))

    def test_chave_privada_e_pega(self):
        linha = '-----BEGIN ' + 'RSA PRIVATE KEY-----'
        self.assertEqual(['chave-privada'], regras('docs/qualquer.md', linha))

    def test_tokens_de_formato_conhecido_sao_pegos(self):
        casos = {
            'aws-access-key': 'AKIA' + aleatorio(16, string.ascii_uppercase + string.digits),
            'github-token': 'gh' + 'p_' + aleatorio(36),
            'google-api-key': 'AI' + 'za' + aleatorio(35),
            'google-oauth-secret': 'GOC' + 'SPX-' + aleatorio(28),
            'anthropic-key': 'sk-' + 'ant-' + aleatorio(40),
        }
        for regra, token in casos.items():
            with self.subTest(regra=regra):
                self.assertIn(regra, regras('app/src/Qualquer.php', f'$x = "{token}";'))

    def test_token_ficticio_sequencial_de_teste_e_aceito(self):
        # scripts/testar-deploy-guardas.sh usa um token assim como fixture.
        token = 'gh' + 'p_' + '0123456789' + 'abcdef' * 4 + 'ab'
        self.assertEqual([], regras('scripts/testar-deploy-guardas.sh', f'"{token}"'))

    def test_atribuicao_sensivel_em_arquivo_de_configuracao_e_pega(self):
        for arquivo in ('app/.env', 'config/x.yaml', '.sync-oauth.env.example', 'scripts/deploy.sh'):
            with self.subTest(arquivo=arquivo):
                self.assertEqual(['atribuicao-sensivel'], regras(arquivo, 'R2_SECRET_ACCESS_KEY=' + aleatorio(40)))

    def test_atribuicao_em_codigo_fonte_nao_e_avaliada(self):
        # Em PHP/JS a regra por nome vira ruído (constantes de teste, nomes de campo); fica para o gitleaks.
        self.assertEqual([], regras('app/src/X.php', "private const API_KEY = '" + aleatorio(30) + "';"))

    def test_valores_de_desenvolvimento_declarados_sao_aceitos(self):
        self.assertEqual([], regras('app/.env.dev', 'APP_SECRET=' + aleatorio(32)))
        self.assertEqual([], regras('app/.env.test', 'SYNC_CRYPTO_KEY=' + aleatorio(43)))

    def test_o_mesmo_app_secret_em_app_env_e_pego(self):
        self.assertEqual(['atribuicao-sensivel'], regras('app/.env', 'APP_SECRET=' + aleatorio(32)))

    def test_marca_de_permissao_aceita_a_linha(self):
        linha = 'TOKEN_FIXO=' + aleatorio(30) + '  # segredo:permitido — valor público do fornecedor'
        self.assertEqual([], regras('config/x.yaml', linha))


class NomeDeArquivoTest(unittest.TestCase):
    def test_arquivos_proibidos(self):
        for arquivo in ('.env', 'e2e/.env', 'app/.env.local', 'app/.env.dev.local', '.env.prod',
                        '.env.prod.bak', '.sync-oauth.env', 'certs/privkey.pem', 'x/id_ed25519',
                        'app/config/client_secret_123.json', 'docs/a-service-account.json',
                        'docs/gestao-cobrancas/credencial.txt', 'prod-antes-2026.dump'):
            with self.subTest(arquivo=arquivo):
                self.assertIsNotNone(guarda.verificar_nome(arquivo))

    def test_arquivos_liberados(self):
        for arquivo in ('app/.env', 'app/.env.dev', 'app/.env.test', '.env.prod.example',
                        'e2e/.env.example', '.sync-oauth.env.example', 'app/src/Env.php'):
            with self.subTest(arquivo=arquivo):
                self.assertIsNone(guarda.verificar_nome(arquivo))


class RepositorioTest(unittest.TestCase):
    """Ponta a ponta num repositório git temporário: stage, intervalo e sigilo da saída."""

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.repo = Path(self._tmp.name)
        self.git('init', '-q', '-b', 'master')
        self.git('config', 'user.email', 'teste@exemplo.test')
        self.git('config', 'user.name', 'Teste')
        self.git('config', 'commit.gpgsign', 'false')
        self.escrever('README.md', 'inicio\n')
        self.commitar('inicio')

    def tearDown(self):
        self._tmp.cleanup()

    def git(self, *args: str) -> str:
        return subprocess.run(['git', *args], cwd=self.repo, check=True,
                              capture_output=True, text=True).stdout

    def escrever(self, caminho: str, conteudo: str):
        alvo = self.repo / caminho
        alvo.parent.mkdir(parents=True, exist_ok=True)
        alvo.write_text(conteudo, encoding='utf-8')

    def commitar(self, mensagem: str):
        self.git('add', '-A')
        # Repositório temporário de teste, sem hooks: o commit direto é o cenário que se quer montar.
        self.git('-c', 'core.hooksPath=/dev/null', 'commit', '-q', '-m', mensagem)

    def rodar(self, *args: str) -> tuple[int, str]:
        erro = StringIO()
        cwd = os.getcwd()
        os.chdir(self.repo)
        try:
            with redirect_stderr(erro):
                codigo = guarda.main(list(args))
        finally:
            os.chdir(cwd)
        return codigo, erro.getvalue()

    def test_stage_limpo_passa(self):
        self.escrever('app/.env', 'MAILER_DSN=null://null\n')
        self.git('add', '-A')
        self.assertEqual(0, self.rodar()[0])

    def test_stage_com_segredo_e_recusado_sem_imprimir_o_valor(self):
        senha = senha_de_app_gmail()
        self.escrever('app/.env', 'MAILER_DSN=smtp://conta@gmail.com:' + senha + '@smtp.gmail.com:587\n')
        self.git('add', '-A')
        codigo, saida = self.rodar()
        self.assertEqual(1, codigo)
        self.assertIn('app/.env:1', saida)
        self.assertNotIn(senha, saida)

    def test_remover_linha_com_segredo_nao_e_bloqueado(self):
        # Tirar o segredo do arquivo é a correção; a guarda não pode impedi-la.
        senha = senha_de_app_gmail()
        self.escrever('app/.env', 'MAILER_DSN=smtp://conta@gmail.com:' + senha + '@smtp.gmail.com:587\n')
        self.commitar('segredo antigo')
        self.escrever('app/.env', 'MAILER_DSN=null://null\n')
        self.git('add', '-A')
        self.assertEqual(0, self.rodar()[0])

    def test_arquivo_proibido_forcado_com_add_f_e_recusado(self):
        self.escrever('.gitignore', '.env.local\n')
        self.escrever('app/.env.local', 'NADA=1\n')
        self.git('add', '-f', 'app/.env.local')
        codigo, saida = self.rodar()
        self.assertEqual(1, codigo)
        self.assertIn('arquivo-proibido', saida)

    def test_intervalo_nao_e_travado_pelo_segredo_do_historico(self):
        # O caso do 3ee0082a: segredo num commit ANTIGO, já na base. O PR novo, limpo, tem de passar.
        self.escrever('app/.env', 'MAILER_DSN=smtp://conta@gmail.com:' + senha_de_app_gmail() + '@smtp.gmail.com:587\n')
        self.commitar('segredo antigo')
        base = self.git('rev-parse', 'HEAD').strip()
        self.escrever('app/.env', 'MAILER_DSN=null://null\n')
        self.commitar('limpa')
        self.escrever('app/x.txt', 'trabalho novo\n')
        self.commitar('feature')
        self.assertEqual(0, self.rodar('--intervalo', f'{base}..HEAD')[0])

    def test_intervalo_pega_segredo_em_commit_novo_mesmo_se_removido_depois(self):
        base = self.git('rev-parse', 'HEAD').strip()
        senha = aleatorio(24)
        self.escrever('deploy.sh', 'export DB_PASSWORD=' + senha + '\n')
        self.commitar('vaza')
        self.escrever('deploy.sh', 'export DB_PASSWORD="$DB_PASSWORD"\n')
        self.commitar('remove')
        codigo, saida = self.rodar('--intervalo', f'{base}..HEAD')
        self.assertEqual(1, codigo)
        self.assertNotIn(senha, saida)


class HookPreCommitTest(unittest.TestCase):
    """O hook real (scripts/git-hooks/pre-commit): trava de branch preservada + guarda encadeada."""

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        base = Path(self._tmp.name)
        self.hooks = base / 'hooks'
        self.hooks.mkdir()
        for nome in ('pre-commit', 'verificar-segredos.py'):
            (self.hooks / nome).write_bytes((AQUI / nome).read_bytes())
        self.repo = base / 'repo'
        self.repo.mkdir()
        self.git('init', '-q', '-b', 'frente-x')
        self.git('config', 'user.email', 'teste@exemplo.test')
        self.git('config', 'user.name', 'Teste')
        # Sem nenhum commit, `rev-parse --abbrev-ref HEAD` falha e o hook recusa antes de tudo.
        (self.repo / 'README.md').write_text('inicio\n')
        self.git('add', 'README.md')
        self.git('-c', 'core.hooksPath=/dev/null', 'commit', '-q', '-m', 'inicio')
        (self.repo / '.frente').write_text('frente-x\n')
        (self.repo / 'a.txt').write_text('a\n')
        self.git('add', 'a.txt')

    def tearDown(self):
        self._tmp.cleanup()

    def git(self, *args: str):
        subprocess.run(['git', *args], cwd=self.repo, check=True, capture_output=True)

    def hook(self) -> tuple[int, str]:
        r = subprocess.run(['bash', str(self.hooks / 'pre-commit')], cwd=self.repo,
                           capture_output=True, text=True)
        return r.returncode, r.stdout + r.stderr

    def test_stage_limpo_na_branch_certa_passa(self):
        self.assertEqual(0, self.hook()[0])

    def test_segredo_no_stage_e_recusado_sem_imprimir_o_valor(self):
        senha = senha_de_app_gmail()
        (self.repo / 'app').mkdir()
        (self.repo / 'app/.env').write_text('MAILER_DSN=smtp://conta@gmail.com:' + senha + '@smtp.gmail.com:587\n')
        self.git('add', 'app/.env')
        codigo, saida = self.hook()
        self.assertEqual(1, codigo)
        self.assertIn('url-com-credencial', saida)
        self.assertNotIn(senha, saida)

    def test_trava_de_branch_continua_valendo_antes_da_guarda(self):
        self.git('switch', '-q', '-c', 'outra')
        codigo, saida = self.hook()
        self.assertEqual(1, codigo)
        self.assertIn('branch ERRADA', saida)

    def test_guarda_ausente_recusa_o_commit(self):
        (self.hooks / 'verificar-segredos.py').unlink()
        codigo, saida = self.hook()
        self.assertEqual(1, codigo)
        self.assertIn('guarda de segredos ausente', saida)


if __name__ == '__main__':
    unittest.main()

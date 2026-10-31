"""Testes do scripts/mudancas.py, sempre numa cópia descartável do repositório.

Rodar: python3 -m unittest scripts/test_mudancas.py -v
"""
import pathlib
import shutil
import subprocess
import sys
import tempfile
import textwrap
import unittest

RAIZ = pathlib.Path(__file__).resolve().parent.parent

CHANGELOG = textwrap.dedent("""\
    # Changelog

    ## [Não lançado]

    ## [2.0.0] - 2026-10-17

    ### Adicionado

    - Algo antigo.

    ## [1.0.0] - 2026-03-31

    Primeira.

    [Não lançado]: https://github.com/sfbdata/Prime/compare/v2.0.0...HEAD
    [2.0.0]: https://github.com/sfbdata/Prime/compare/v1.0.0...v2.0.0
    [1.0.0]: https://github.com/sfbdata/Prime/releases/tag/v1.0.0
    """)


class Base(unittest.TestCase):
    def setUp(self):
        self.dir = pathlib.Path(tempfile.mkdtemp())
        (self.dir / 'scripts').mkdir()
        (self.dir / 'mudancas').mkdir()
        (self.dir / 'app/src/Shared').mkdir(parents=True)
        (self.dir / 'app/config').mkdir(parents=True)
        shutil.copy(RAIZ / 'scripts/mudancas.py', self.dir / 'scripts/mudancas.py')
        shutil.copy(RAIZ / 'scripts/release.sh', self.dir / 'scripts/release.sh')
        (self.dir / 'CHANGELOG.md').write_text(CHANGELOG)
        (self.dir / 'app/src/Shared/VersaoDoSistema.php').write_text(
            "<?php\nfinal class VersaoDoSistema\n{\n    public const ATUAL = '2.0.0';\n}\n")
        (self.dir / 'mudancas/README.md').write_text('Instruções.')

    def tearDown(self):
        shutil.rmtree(self.dir)

    def mudanca(self, nome, tipo, changelog='- Item técnico.', cliente=None):
        if cliente is None:
            cliente = '' if tipo in ('interno', 'remoção', 'remocao', 'segurança', 'seguranca') else '- Texto para o escritório.'
        corpo = f'---\ntipo: {tipo}\n---\n## Changelog\n{changelog}\n'
        if cliente:
            corpo += f'\n## Para o cliente\n{cliente}\n'
        (self.dir / 'mudancas' / nome).write_text(corpo)

    def roda(self, *args):
        return subprocess.run([sys.executable, '-I', str(self.dir / 'scripts/mudancas.py'), *args],
                              capture_output=True, text=True)


class Versao(Base):
    def test_sem_mudancas_nada_a_lancar(self):
        self.assertEqual(self.roda('proxima').stdout.strip(), '')

    def test_so_interno_nao_gera_versao(self):
        self.mudanca('ci.md', 'interno', changelog='')
        self.assertEqual(self.roda('proxima').stdout.strip(), '')

    def test_correcao_sobe_patch(self):
        self.mudanca('a.md', 'correção')
        self.assertEqual(self.roda('proxima').stdout.strip(), '2.0.1')

    def test_novidade_sobe_minor_e_vence_correcao(self):
        self.mudanca('a.md', 'correcao')
        self.mudanca('b.md', 'novidade')
        self.assertEqual(self.roda('proxima').stdout.strip(), '2.1.0')

    def test_remocao_sobe_major(self):
        self.mudanca('a.md', 'novidade')
        self.mudanca('b.md', 'remoção')
        self.assertEqual(self.roda('proxima').stdout.strip(), '3.0.0')


class Validacao(Base):
    def test_tipo_invalido(self):
        self.mudanca('a.md', 'melhoria')
        r = self.roda('validar')
        self.assertEqual(r.returncode, 1)
        self.assertIn('tipo "melhoria" inválido', r.stderr)

    def test_changelog_vazio_so_para_interno(self):
        self.mudanca('a.md', 'novidade', changelog='')
        self.assertEqual(self.roda('validar').returncode, 1)

    def test_item_sem_traco(self):
        self.mudanca('a.md', 'novidade', changelog='Sem traço de lista.')
        self.assertEqual(self.roda('validar').returncode, 1)

    def test_travessao_recusado(self):
        self.mudanca('a.md', 'novidade', cliente='- Agora — mais rápido.')
        r = self.roda('validar')
        self.assertEqual(r.returncode, 1)
        self.assertIn('travessão', r.stderr)

    def test_sem_cabecalho(self):
        (self.dir / 'mudancas/a.md').write_text('## Changelog\n- x\n')
        self.assertEqual(self.roda('validar').returncode, 1)

    def test_sem_para_o_cliente_falha_nos_tipos_da_pagina(self):
        for tipo in ('novidade', 'alteração', 'correcao'):
            with self.subTest(tipo=tipo):
                self.mudanca('a.md', tipo, cliente='')
                r = self.roda('validar')
                self.assertEqual(r.returncode, 1)
                self.assertIn('falta "## Para o cliente"', r.stderr)

    def test_interno_continua_sem_texto_ao_cliente(self):
        self.mudanca('a.md', 'interno', changelog='', cliente='')
        self.assertEqual(self.roda('validar').returncode, 0)

    def test_frase_de_sem_efeito_recusada(self):
        for frase in ('Sem efeito para o cliente.', 'Nada muda para os escritórios.', 'Nenhuma mudança visível.',
                      'Sem mudança na tela.'):
            with self.subTest(frase=frase):
                self.mudanca('a.md', 'novidade', cliente=f'- {frase}')
                r = self.roda('validar')
                self.assertEqual(r.returncode, 1)
                self.assertIn('não pode dizer que nada mudou', r.stderr)

    def test_valido(self):
        self.mudanca('a.md', 'novidade', cliente='- Texto para o escritório.')
        r = self.roda('validar')
        self.assertEqual(r.returncode, 0, r.stderr)


class Aplicar(Base):
    def test_monta_a_versao_inteira(self):
        self.mudanca('a.md', 'correcao', changelog='- Conserta X.', cliente='- Corrigido X.')
        self.mudanca('b.md', 'novidade', changelog='- Tela Y.\n  com detalhe.', cliente='- Agora há Y.')
        self.mudanca('c.md', 'interno', changelog='')
        r = self.roda('aplicar', '--data', '2026-10-20')
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(r.stdout.strip(), '2.1.0')

        cl = (self.dir / 'CHANGELOG.md').read_text()
        self.assertIn('## [Não lançado]\n\n## [2.1.0] - 2026-10-20\n\n### Adicionado\n\n- Tela Y. com detalhe.'
                      '\n\n### Corrigido\n\n- Conserta X.\n\n## [2.0.0]', cl)
        self.assertIn('[Não lançado]: https://github.com/sfbdata/Prime/compare/v2.1.0...HEAD\n'
                      '[2.1.0]: https://github.com/sfbdata/Prime/compare/v2.0.0...v2.1.0\n', cl)
        self.assertIn("ATUAL = '2.1.0'", (self.dir / 'app/src/Shared/VersaoDoSistema.php').read_text())
        nov = (self.dir / 'app/config/novidades.yaml').read_text()
        self.assertIn('versoes:\n- versao: "2.1.0"\n  data: "2026-10-20"\n  itens:\n    - "Corrigido X."\n    - "Agora há Y."\n', nov)
        restantes = sorted(p.name for p in (self.dir / 'mudancas').iterdir())
        self.assertEqual(restantes, ['README.md'])

        # O resultado é exatamente o que o workflow Release aceita.
        v = subprocess.run(['bash', str(self.dir / 'scripts/release.sh'), 'validar', '2.1.0'],
                           capture_output=True, text=True)
        self.assertEqual(v.returncode, 0, v.stdout + v.stderr)

    def test_segunda_versao_vai_para_o_topo_das_novidades(self):
        self.mudanca('a.md', 'novidade', cliente='- Primeira.')
        self.roda('aplicar', '--data', '2026-10-20')
        self.mudanca('b.md', 'correcao', cliente='- Segunda.')
        self.roda('aplicar', '--data', '2026-10-21')
        nov = (self.dir / 'app/config/novidades.yaml').read_text()
        self.assertLess(nov.index('"2.1.1"'), nov.index('"2.1.0"'))
        self.assertEqual(nov.count('versoes:'), 1)

    def test_recusa_texto_a_mao_em_nao_lancado(self):
        cl = (self.dir / 'CHANGELOG.md').read_text().replace(
            '## [Não lançado]\n', '## [Não lançado]\n\n- Escrito à mão.\n')
        (self.dir / 'CHANGELOG.md').write_text(cl)
        self.mudanca('a.md', 'novidade')
        r = self.roda('aplicar', '--data', '2026-10-20')
        self.assertEqual(r.returncode, 1)
        self.assertIn('escrito à mão', r.stderr)

    def test_nada_a_lancar(self):
        self.mudanca('a.md', 'interno', changelog='')
        self.assertEqual(self.roda('aplicar', '--data', '2026-10-20').returncode, 1)


class ReleaseRecusaComMudancaPendente(Base):
    def test_release_sh_recusa_arquivo_em_mudancas(self):
        self.mudanca('a.md', 'novidade')
        self.roda('aplicar', '--data', '2026-10-20')
        self.mudanca('outro-pr.md', 'correcao')
        v = subprocess.run(['bash', str(self.dir / 'scripts/release.sh'), 'validar', '2.1.0'],
                           capture_output=True, text=True)
        self.assertEqual(v.returncode, 1)
        self.assertIn('mudancas/', v.stdout + v.stderr)


if __name__ == '__main__':
    unittest.main()

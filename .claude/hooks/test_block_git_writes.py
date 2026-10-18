"""Testes do guard block-git-writes.py: o que o Claude pode e não pode rodar.

Rodar: python3 -m unittest discover -s .claude/hooks -p 'test_*.py' -v
"""
import json
import pathlib
import subprocess
import sys
import unittest

HOOK = pathlib.Path(__file__).with_name('block-git-writes.py')


def roda(comando: str) -> int:
    entrada = json.dumps({'tool_input': {'command': comando}})
    return subprocess.run([sys.executable, str(HOOK)], input=entrada, text=True,
                          capture_output=True).returncode


class Liberado(unittest.TestCase):
    CASOS = [
        'git status',
        'git log --oneline -5',
        'git add CHANGELOG.md',
        'git commit -q -m "Ajusta o CI"',
        'git cherry-pick abc1234',
        'git merge-base --is-ancestor a b',
        'git fetch -q origin',
        # push de branch de trabalho
        'git push -u origin eng-seguranca-fluxo',
        'git -C /repo/.claude/worktrees/x push -u origin x',
        'git push origin release-automatica-teste',
        'git push --set-upstream origin fix-algo',
        # gh: leitura e abrir PR
        'gh pr create --repo sfbdata/Prime --base master --head x --title "T" --body "B"',
        'gh pr view 24 --repo sfbdata/Prime',
        'gh pr checks 24 --repo sfbdata/Prime --watch',
        'gh run list --repo sfbdata/Prime -L 5',
        'gh run view 123 --log',
        'gh api repos/sfbdata/Prime/rulesets -q length',
        'gh api -X GET repos/sfbdata/Prime/branches',
        'gh release view v2.0.0 --repo sfbdata/Prime',
        # texto que só menciona os comandos (corpo de PR, mensagem de commit)
        'gh pr create --title "Ajusta" --body "Depois: gh pr merge e git push origin master"',
        'git commit -m "Documenta que gh workflow run release.yml é do dono"',
        # -n de OUTRO comando no mesmo encadeamento não é flag do commit
        'bash -n scripts/x.sh && git add x && git commit -q -m "y"',
        'grep -n foo a.txt; git commit -m "y"',
    ]

    def test_liberados(self):
        for c in self.CASOS:
            with self.subTest(c):
                self.assertEqual(roda(c), 0, c)


class Bloqueado(unittest.TestCase):
    CASOS = [
        # publicação no master, forçada ou destrutiva
        'git push origin master',
        'git push origin main',
        'git push origin HEAD:master',
        'git push origin x:refs/heads/master',
        'git push origin :x',
        'git push',
        'git push origin',
        'git push --force origin x',
        'git push -f origin x',
        'git push --force-with-lease origin x',
        'git push origin +x',
        'git push --delete origin x',
        'git push -d origin x',
        'git push origin --tags',
        'git push origin v3.0.0',
        'git push origin refs/tags/v3.0.0',
        'git push --all origin',
        'git push --mirror origin',
        'git push -u origin x && git push origin master',
        # reescrita local
        'git pull',
        'git reset --hard origin/master',
        'git rebase master',
        'git merge x',
        'git checkout master',
        'git branch -D x',
        'git tag -d v1.0.0',
        'git stash pop',
        'git commit --no-verify -m "x"',
        'git commit -n -m "x"',
        'git add x && git commit -qn -m "x"',
        'git -c core.hooksPath=/dev/null commit -m "x"',
        # gh: merge = deploy; disparos e configuração do repositório
        'gh pr merge 24 --merge',
        'gh pr merge --repo sfbdata/Prime 24',
        'gh workflow run release.yml -f versao=9.9.9',
        'gh workflow disable release.yml',
        'gh release create v9.9.9',
        'gh release delete v2.0.0',
        'gh secret set X --env release',
        'gh variable set X --body y',
        'gh repo edit --visibility private',
        'gh api -X POST repos/sfbdata/Prime/rulesets --input r.json',
        'gh api --method DELETE repos/sfbdata/Prime/rulesets/1',
        'gh api -X PUT repos/sfbdata/Prime/rulesets/1 -f enforcement=disabled',
        'gh api repos/sfbdata/Prime/environments/release/deployment-branch-policies -f name=x',
        'gh api repos/sfbdata/Prime/dispatches -F event_type=x',
        'gh api repos/x/y/git/refs --input ref.json',
        'gh run rerun 123',
        'gh run cancel 123',
    ]

    def test_bloqueados(self):
        for c in self.CASOS:
            with self.subTest(c):
                self.assertEqual(roda(c), 2, c)

    def test_entrada_ilegivel_falha_fechado(self):
        r = subprocess.run([sys.executable, str(HOOK)], input='nao e json', text=True,
                           capture_output=True)
        self.assertEqual(r.returncode, 2)


if __name__ == '__main__':
    unittest.main()


class TrazerOMasterParaABranch(unittest.TestCase):
    """`git merge origin/master` só numa branch de trabalho: atualizar a frente antes do merge do PR."""

    @classmethod
    def setUpClass(cls):
        import tempfile
        cls.dir = pathlib.Path(tempfile.mkdtemp())
        for nome, branch in (('frente', 'minha-frente'), ('principal', 'master')):
            d = cls.dir / nome
            subprocess.run(['git', 'init', '-q', '-b', branch, str(d)], check=True)
        cls.frente = str(cls.dir / 'frente')
        cls.principal = str(cls.dir / 'principal')

    @classmethod
    def tearDownClass(cls):
        import shutil
        shutil.rmtree(cls.dir)

    def roda_em(self, comando, cwd):
        entrada = json.dumps({'tool_input': {'command': comando}, 'cwd': cwd})
        return subprocess.run([sys.executable, str(HOOK)], input=entrada, text=True,
                              capture_output=True).returncode

    def test_liberado_na_branch_de_trabalho(self):
        for c in ['git merge origin/master', 'git merge --no-edit origin/master', 'git merge -q --no-edit origin/master',
                  'git fetch -q origin && git merge --no-edit origin/master', 'git merge --abort', 'git merge --continue']:
            with self.subTest(c):
                self.assertEqual(self.roda_em(c, self.frente), 0, c)
        self.assertEqual(self.roda_em(f'git -C {self.frente} merge --no-edit origin/master', self.principal), 0)

    def test_bloqueado_no_master(self):
        self.assertEqual(self.roda_em('git merge origin/master', self.principal), 2)
        self.assertEqual(self.roda_em(f'git -C {self.principal} merge origin/master', self.frente), 2)

    def test_bloqueado_outro_alvo_ou_forma(self):
        for c in ['git merge minha-outra-branch', 'git merge origin/release/proxima', 'git merge --squash origin/master',
                  'git merge -X theirs origin/master', 'git merge --no-verify origin/master', 'git merge origin/master outra',
                  'cd /outro && git merge origin/master']:
            with self.subTest(c):
                self.assertEqual(self.roda_em(c, self.frente), 2, c)

    def test_sem_cwd_falha_fechado(self):
        self.assertEqual(roda('git merge origin/master'), 2)

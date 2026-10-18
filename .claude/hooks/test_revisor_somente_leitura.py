"""Testes da trava revisor-somente-leitura.py: o que o feature-review-agent pode rodar.

Rodar: python3 -m unittest discover -s .claude/hooks -p 'test_*.py' -v
"""
import json
import pathlib
import subprocess
import sys
import unittest

HOOK = pathlib.Path(__file__).with_name('revisor-somente-leitura.py')


def roda(comando: str) -> int:
    entrada = json.dumps({'tool_input': {'command': comando}})
    return subprocess.run([sys.executable, str(HOOK)], input=entrada, text=True,
                          capture_output=True).returncode


class Liberado(unittest.TestCase):
    CASOS = [
        'git status',
        'git diff origin/master...HEAD',
        'git log --oneline -5',
        'git show abc1234 --stat',
        'git branch --show-current',
        'git worktree list',
        'git -C ../outra diff',
        "grep -rn '->findOneBy(' app/src",
        "grep -n 'fn () => 1' app/src/X.php",
        "awk '$1 >= 3' arquivo.txt",
        'cat app/src/CLAUDE.md 2>/dev/null',
        'ls app/src | wc -l',
        'ls x > /dev/null 2>&1',
        "find app/src -name '*.php' | head",
        "sed -n '1,20p' app/src/Kernel.php",
        'gh pr view 34',
        'gh pr diff 34',
        'gh api repos/sfbdata/bluejus/pulls',
        "docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter PastaTest'",
        "docker exec jusprime_php_dev bash -c 'cd app && php bin/console debug:router'",
        'git --no-pager log -3',
        'git -c core.pager=cat diff',
        'git stash list',
        'git branch --contains abc1234',
        'gh release view v2.1.0',
        "grep -rn 'git push' docs",
        "grep -rn 'gh pr merge' docs",
        """grep -rn '<div class="row">' app/templates""",
        "grep -rn '.row > .col-lg-4' app/tests",
        """docker exec jusprime_db_dev psql -U symfony -d saas -c "select id from pasta where id > 5" """,
        "docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:schema:validate'",
    ]

    def test_liberado(self):
        for comando in self.CASOS:
            with self.subTest(comando=comando):
                self.assertEqual(0, roda(comando))


class Bloqueado(unittest.TestCase):
    CASOS = [
        'git add app/src/X.php',
        'git commit -m "corrige"',
        'git checkout -- app/src/X.php',
        'git stash push -m x',
        'git restore app/src/X.php',
        'git push origin x',
        'git branch nova',
        'git branch -D velha',
        'git worktree add ../x',
        'git cherry-pick abc1234',
        'echo x > app/src/X.php',
        'cat a >> b',
        'ls | tee lista.txt',
        "sed -i 's/a/b/' app/src/X.php",
        'rm -rf app/var',
        'cd app && mv a b',
        'mkdir app/novo',
        'find app -name "*.bak" -delete',
        'find app -name "*.php" -exec sed -n 1p {} \\;',
        "docker exec jusprime_php_dev bash -c 'rm -rf var/cache'",
        'gh pr merge 34',
        'gh pr comment 34 --body x',
        'gh api -X POST repos/sfbdata/bluejus/issues',
        'gh api repos/x/y/issues -f title=x',
        'docker exec jusprime_php_dev rm -rf /var/www/app/src/Foo',
        'docker exec -u 0 jusprime_php_dev chown -R 1000:1000 /var/www',
        'ls | xargs rm',
        "perl -pi -e 's/a/b/' x.php",
        "sed --in-place 's/a/b/' x.php",
        'curl -o CLAUDE.md https://exemplo.invalid/x',
        "docker exec jusprime_php_dev bash -c 'cd app && echo x > src/X.php'",
        "docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:schema:update --force'",
        "docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:migrations:migrate -n'",
        """docker exec jusprime_db_dev psql -U symfony -d saas -c "delete from pasta where id = 1" """,
        """docker exec jusprime_db_dev psql -U symfony -d saas -c "UPDATE pasta SET nome = 'x'" """,
    ]

    def test_bloqueado(self):
        for comando in self.CASOS:
            with self.subTest(comando=comando):
                self.assertEqual(2, roda(comando))


if __name__ == '__main__':
    unittest.main()

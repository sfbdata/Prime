# Pastas aninhadas no gerenciador de arquivos — Plano de Implementação (Entrega 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir criar pasta dentro de pasta (até 10 níveis) no gerenciador de arquivos da pasta do expediente, dentro do sistema, sem tocar em nenhuma das 623 seções que já existem em produção.

**Architecture:** Auto-referência em `pasta_secao` (`secao_pai_id` nullable). A árvore de uma pasta tem no máximo 62 nós em produção, então ela é carregada inteira numa query e montada em PHP — nada de caminho materializado. `NULL` no pai significa "raiz da pasta", que é exatamente o que as 623 linhas atuais já são: migration aditiva, sem backfill.

**Tech Stack:** PHP 8.2, Symfony 7.4, Doctrine ORM 3.x, PostgreSQL 15, Twig, Bootstrap 5, SortableJS, PHPUnit (DAMA + Foundry).

**Spec:** [`docs/specs/pasta-subpastas-aninhadas.md`](../../specs/pasta-subpastas-aninhadas.md) — leia antes de começar. Este plano implementa **só a Entrega 1** (§4 da spec).

## Global Constraints

- **Idioma:** código, comentários e commits em **português brasileiro**. `camelCase` métodos/variáveis · `PascalCase` classes · `snake_case` rotas/colunas.
- **Teto de profundidade: 10.** Seção com `pai = NULL` está no **nível 1**. Nível 10 é válido; nível 11 é recusado.
- **Altura de subárvore:** quantos níveis ela ocupa a partir da própria raiz, contando ela mesma. Pasta sem filhas tem altura 1.
- **Multi-tenant é inegociável:** mãe e filha sempre no mesmo tenant. Toda tarefa que toca UseCase precisa de teste cross-tenant.
- **`pasta_id` continua NOT NULL em toda seção**, inclusive aninhada. Mãe e filha sempre na mesma pasta.
- **`ordem` passa a significar posição entre IRMÃS**, não posição na pasta.
- **Nomes repetidos entre irmãs são PERMITIDOS.** Não escreva validação de unicidade (spec §3).
- **Todo comando roda dentro do container.** Nunca `php`/`composer`/`bin/console` fora dele.
- **Na worktree, a suíte é `scripts/frente-testar.sh pasta-subpastas-aninhadas`.** `cd app && php bin/phpunit` testa o **repositório principal** e dá verde falso.
- **Não abra o navegador.** O smoke é do dono (spec §10.4).

---

### Task 0: Abrir a frente

**Files:**
- Modify: `docs/frentes-ativas.md`

**Interfaces:**
- Produces: worktree `pasta-subpastas-aninhadas` cortada de `origin/master`, com `vendor/`, `uploads/` e banco de teste próprio.

- [ ] **Step 1: Conferir que nenhuma outra frente com migration está ativa**

```bash
grep -n "sim" docs/frentes-ativas.md
```

Esperado: só `cobranca-acompanhamento-canonico`, e ela está marcada **🛑 PARADA**. Se alguma outra frente com migration estiver ativa e andando, **pare e alinhe com o dono** — a regra é uma frente com migration por vez.

- [ ] **Step 2: Abrir a worktree**

```bash
scripts/frente-abrir.sh pasta-subpastas-aninhadas
```

- [ ] **Step 3: Registrar a frente**

Acrescentar a linha na tabela de `docs/frentes-ativas.md`:

```markdown
| `pasta-subpastas-aninhadas` | Pasta (gerenciador de arquivos) | **sim — 1** | `app/templates/pasta/show.html.twig`, `app/public/js/pasta-arquivos.js` | implementando | `origin/master` @ `2561ba7c` |
```

⚠️ Acrescentar também o aviso de colisão logo abaixo da tabela:

```markdown
⚠️ `pasta-subpastas-aninhadas` e `expediente-ux` tocam **os mesmos arquivos de tela**
(`app/templates/pasta/show.html.twig`). Quem integrar por último traz o master para dentro
e roda a suíte de novo ANTES do merge.
```

- [ ] **Step 4: Commit**

```bash
git add docs/frentes-ativas.md
git commit -m "abre a frente de pastas aninhadas no gerenciador de arquivos"
```

---

### Task 1: Entidade e migration

**Files:**
- Modify: `app/src/Pasta/Entity/PastaSecao.php`
- Create: `app/migrations/VersionYYYYMMDDHHMMSS.php` (gerada)
- Test: `app/tests/Pasta/Unit/PastaSecaoArvoreTest.php`

**Interfaces:**
- Produces:
  - `PastaSecao::getPai(): ?PastaSecao`
  - `PastaSecao::setPai(?PastaSecao $pai): self`
  - `PastaSecao::getFilhas(): Collection<int, PastaSecao>`
  - `PastaSecao::getProfundidade(): int` — raiz = 1
  - `PastaSecao::getAltura(): int` — folha = 1
  - `PastaSecao::descendeDe(PastaSecao $possivelAncestral): bool`

- [ ] **Step 1: Escrever os testes que falham**

Criar `app/tests/Pasta/Unit/PastaSecaoArvoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\PastaSecao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PastaSecao::class)]
final class PastaSecaoArvoreTest extends TestCase
{
    private function secao(string $nome, ?PastaSecao $pai = null): PastaSecao
    {
        return (new PastaSecao())->setNome($nome)->setPai($pai);
    }

    public function testSecaoSemPaiEstaNoNivelUm(): void
    {
        self::assertSame(1, $this->secao('RAIZ')->getProfundidade());
    }

    public function testProfundidadeContaACadeiaInteira(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B', $a);
        $c = $this->secao('C', $b);

        self::assertSame(3, $c->getProfundidade());
    }

    public function testAlturaDeFolhaEhUm(): void
    {
        self::assertSame(1, $this->secao('FOLHA')->getAltura());
    }

    public function testAlturaContaORamoMaisFundo(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B', $a);
        $a->getFilhas()->add($b);
        $c = $this->secao('C', $b);
        $b->getFilhas()->add($c);
        // ramo curto, para provar que a altura pega o MAIOR
        $d = $this->secao('D', $a);
        $a->getFilhas()->add($d);

        self::assertSame(3, $a->getAltura());
    }

    public function testDescendeDeEnxergaAvo(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B', $a);
        $c = $this->secao('C', $b);

        self::assertTrue($c->descendeDe($a));
    }

    public function testNaoDescendeDeIrma(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B', $a);
        $c = $this->secao('C', $a);

        self::assertFalse($b->descendeDe($c));
    }

    public function testNaoDescendeDeSiMesma(): void
    {
        $a = $this->secao('A');

        self::assertFalse($a->descendeDe($a));
    }
}
```

- [ ] **Step 2: Rodar para confirmar que falha**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter PastaSecaoArvoreTest
```

Esperado: FAIL com `Call to undefined method App\Pasta\Entity\PastaSecao::setPai()`.

- [ ] **Step 3: Implementar na entidade**

Em `app/src/Pasta/Entity/PastaSecao.php`, acrescentar o índice na classe:

```php
#[ORM\Index(name: 'idx_pasta_secao_pai', columns: ['secao_pai_id'])]
```

Acrescentar as propriedades (junto das demais, antes do construtor):

```php
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'filhas')]
    #[ORM\JoinColumn(name: 'secao_pai_id', nullable: true, onDelete: 'CASCADE')]
    private ?self $pai = null;

    /** @var Collection<int, PastaSecao> */
    #[ORM\OneToMany(mappedBy: 'pai', targetEntity: self::class, cascade: ['remove'])]
    private Collection $filhas;
```

No construtor, inicializar:

```php
        $this->filhas = new ArrayCollection();
```

E os métodos:

```php
    public function getPai(): ?self
    {
        return $this->pai;
    }

    public function setPai(?self $pai): self
    {
        $this->pai = $pai;

        return $this;
    }

    /** @return Collection<int, PastaSecao> */
    public function getFilhas(): Collection
    {
        return $this->filhas;
    }

    /**
     * Nível desta seção na árvore. Seção sem pai está no nível 1.
     *
     * O teto de LIMITE_SEGURANCA não é o teto de produto (que é 10, validado nos UseCases) — é
     * uma trava contra ciclo gravado no banco, que aqui viraria laço infinito.
     */
    public function getProfundidade(): int
    {
        $nivel = 1;
        $atual = $this->pai;
        while ($atual !== null && $nivel < self::LIMITE_SEGURANCA) {
            ++$nivel;
            $atual = $atual->getPai();
        }

        return $nivel;
    }

    /** Quantos níveis a subárvore desta seção ocupa, contando ela mesma. Folha = 1. */
    public function getAltura(): int
    {
        $altura = 1;
        foreach ($this->filhas as $filha) {
            $altura = max($altura, 1 + $filha->getAltura());
        }

        return $altura;
    }

    /** Esta seção está em algum lugar abaixo de $possivelAncestral? Não considera a si mesma. */
    public function descendeDe(self $possivelAncestral): bool
    {
        $passos = 0;
        $atual  = $this->pai;
        while ($atual !== null && $passos < self::LIMITE_SEGURANCA) {
            if ($atual === $possivelAncestral) {
                return true;
            }
            $atual = $atual->getPai();
            ++$passos;
        }

        return false;
    }
```

E a constante, no topo da classe:

```php
    /** Trava anti-laço na travessia da árvore (não é o teto de produto, que é 10). */
    private const LIMITE_SEGURANCA = 100;
```

- [ ] **Step 4: Rodar os testes**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter PastaSecaoArvoreTest
```

Esperado: PASS (7 testes).

- [ ] **Step 5: Fotografar a divergência que já existia ANTES de gerar a migration**

```bash
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/pasta-subpastas-aninhadas/app && php bin/console doctrine:schema:update --dump-sql'
```

Guarde a saída. **Tudo que aparecer aqui já era divergência de outra frente e precisa SAIR do arquivo gerado no passo seguinte** — em especial qualquer `DROP INDEX` de índice funcional, que o Doctrine não sabe representar e propõe apagar.

- [ ] **Step 6: Gerar a migration**

```bash
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/pasta-subpastas-aninhadas/app && php bin/console make:migration'
```

- [ ] **Step 7: Limpar a migration gerada**

Abrir o arquivo em `app/migrations/`. O `up()` deve conter **exatamente** isto, e nada mais:

```php
$this->addSql('ALTER TABLE pasta_secao ADD secao_pai_id INT DEFAULT NULL');
$this->addSql('ALTER TABLE pasta_secao ADD CONSTRAINT FK_PASTA_SECAO_PAI FOREIGN KEY (secao_pai_id) REFERENCES pasta_secao (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
$this->addSql('CREATE INDEX idx_pasta_secao_pai ON pasta_secao (secao_pai_id)');
```

Apagar qualquer outra linha (é resíduo de outra frente, conforme o passo 5). O `down()` desfaz os três.

- [ ] **Step 8: Aplicar nos DOIS bancos**

```bash
# banco da suíte da frente
docker exec -e TEST_TOKEN=pasta-subpastas-aninhadas jusprime_php_dev bash -c \
  'cd /var/www/.claude/worktrees/pasta-subpastas-aninhadas/app && php bin/console doctrine:migrations:migrate --env=test --no-interaction'

# banco da aplicação (o que a tela usa)
docker exec jusprime_php_dev bash -c \
  'cd /var/www/app && php bin/console doctrine:migrations:migrate --no-interaction'
```

- [ ] **Step 9: Validar o mapeamento**

```bash
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/pasta-subpastas-aninhadas/app && php bin/console doctrine:schema:validate'
```

Esperado: mapeamento e banco OK.

- [ ] **Step 10: Commit**

```bash
git add app/src/Pasta/Entity/PastaSecao.php app/migrations/ app/tests/Pasta/Unit/PastaSecaoArvoreTest.php
git commit -m "adiciona pai na secao da pasta, com travessia de arvore"
```

---

### Task 2: Repository

**Files:**
- Modify: `app/src/Pasta/Repository/PastaSecaoRepository.php:36-48`
- Test: `app/tests/Pasta/Functional/PastaSecaoRepositoryTest.php`

**Interfaces:**
- Consumes: `PastaSecao::getPai()`, `getFilhas()` (Task 1)
- Produces:
  - `proximaOrdem(Pasta $pasta, Tenant $tenant, ?PastaSecao $pai = null): int` — **assinatura mudou**, o 3º parâmetro é novo e opcional
  - `contarConteudoRecursivo(PastaSecao $secao): array{subpastas: int, arquivos: int}`

- [ ] **Step 1: Escrever os testes que falham**

Criar `app/tests/Pasta/Functional/PastaSecaoRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaSecaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PastaSecaoRepository::class)]
final class PastaSecaoRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PastaSecaoRepository $repo;
    private Tenant $tenant;
    private Pasta $pasta;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(PastaSecaoRepository::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Tenant Arvore ' . uniqid());
        $this->em->persist($this->tenant);

        $this->pasta = new Pasta();
        $this->pasta->setNup('TEST-ARV-' . uniqid());
        $this->pasta->setTenant($this->tenant);
        $this->em->persist($this->pasta);
        $this->em->flush();
    }

    private function criarSecao(string $nome, ?PastaSecao $pai, int $ordem): PastaSecao
    {
        $secao = (new PastaSecao())
            ->setNome($nome)
            ->setPai($pai)
            ->setOrdem($ordem);
        $secao->setPasta($this->pasta);
        $secao->setTenant($this->tenant);
        $this->em->persist($secao);
        $this->em->flush();

        return $secao;
    }

    private function criarDocumento(?PastaSecao $secao): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo('DOC ' . uniqid());
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('x/y.pdf');
        $doc->setNomeOriginal('y.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(10);
        $doc->setPasta($this->pasta);
        $doc->setSecao($secao);
        $doc->setTenant($this->tenant);
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    public function testProximaOrdemContaSoAsIrmas(): void
    {
        // duas na raiz: ordens 1 e 2
        $this->criarSecao('RAIZ A', null, 1);
        $paiB = $this->criarSecao('RAIZ B', null, 2);
        // primeira filha de B tem de nascer com ordem 1, NÃO com 3
        self::assertSame(1, $this->repo->proximaOrdem($this->pasta, $this->tenant, $paiB));
    }

    public function testProximaOrdemNaRaizIgnoraAsAninhadas(): void
    {
        $pai = $this->criarSecao('RAIZ A', null, 1);
        $this->criarSecao('FILHA', $pai, 1);
        $this->criarSecao('NETA', $pai, 2);

        self::assertSame(2, $this->repo->proximaOrdem($this->pasta, $this->tenant, null));
    }

    public function testContagemRecursivaSomaAArvoreInteira(): void
    {
        $a = $this->criarSecao('A', null, 1);
        $b = $this->criarSecao('B', $a, 1);
        $c = $this->criarSecao('C', $b, 1);

        $this->criarDocumento($a);
        $this->criarDocumento($b);
        $this->criarDocumento($c);
        $this->criarDocumento($c);
        $this->criarDocumento(null); // na raiz da pasta, NÃO conta

        $total = $this->repo->contarConteudoRecursivo($a);

        self::assertSame(2, $total['subpastas'], 'B e C');
        self::assertSame(4, $total['arquivos'], 'os 4 da árvore de A, sem o da raiz da pasta');
    }

    public function testApagarAMaeApagaFilhasNetasEDocumentos(): void
    {
        $a = $this->criarSecao('A', null, 1);
        $b = $this->criarSecao('B', $a, 1);
        $c = $this->criarSecao('C', $b, 1);

        $docNeta  = $this->criarDocumento($c);
        $docSolto = $this->criarDocumento(null); // na raiz da pasta: TEM de sobreviver

        $idB = $b->getId();
        $idC = $c->getId();
        $idDocNeta  = $docNeta->getId();
        $idDocSolto = $docSolto->getId();

        $this->em->remove($a);
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->em->find(PastaSecao::class, $idB), 'a filha some');
        self::assertNull($this->em->find(PastaSecao::class, $idC), 'a neta some');
        self::assertNull($this->em->find(PastaDocumento::class, $idDocNeta), 'o documento da neta some');
        self::assertNotNull(
            $this->em->find(PastaDocumento::class, $idDocSolto),
            'o documento da RAIZ da pasta não pode ser levado junto',
        );
    }
}
```

> Este teste é o que sustenta a decisão D3 da spec (apagar a pasta leva a árvore). A última asserção
> é a que importa: ela prova que o cascade **para** na fronteira da árvore e não come os 12.587
> documentos que vivem na raiz das pastas em produção.

- [ ] **Step 2: Rodar para confirmar que falha**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter PastaSecaoRepositoryTest
```

Esperado: FAIL — `proximaOrdem()` não aceita 3 argumentos / `contarConteudoRecursivo()` não existe.

- [ ] **Step 3: Implementar**

Substituir `proximaOrdem` em `app/src/Pasta/Repository/PastaSecaoRepository.php`:

```php
    /** Próxima ordem entre as IRMÃS de $pai (ou entre as seções da raiz, se $pai for null). */
    public function proximaOrdem(Pasta $pasta, Tenant $tenant, ?PastaSecao $pai = null): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('MAX(s.ordem)')
            ->andWhere('s.pasta = :pasta')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant);

        if ($pai === null) {
            $qb->andWhere('s.pai IS NULL');
        } else {
            $qb->andWhere('s.pai = :pai')->setParameter('pai', $pai);
        }

        $max = $qb->getQuery()->getSingleScalarResult();

        return ($max === null ? 0 : (int) $max) + 1;
    }
```

E acrescentar:

```php
    /**
     * Conta o que a exclusão de $secao levaria junto. As duas contagens têm escopos DIFERENTES,
     * de propósito, porque é o que o aviso precisa dizer ("contém 3 subpastas e 127 arquivos"):
     *
     *   - subpastas: só as DESCENDENTES; a própria $secao não se conta;
     *   - arquivos:  os da própria $secao MAIS os de toda a descendência.
     *
     * Nunca inclui os documentos que estão na raiz da pasta (secao_id IS NULL) — esses sobrevivem.
     *
     * @return array{subpastas: int, arquivos: int}
     */
    public function contarConteudoRecursivo(PastaSecao $secao): array
    {
        $subpastas = 0;
        $arquivos  = $secao->getDocumentos()->count();

        foreach ($secao->getFilhas() as $filha) {
            $abaixo = $this->contarConteudoRecursivo($filha);
            $subpastas += 1 + $abaixo['subpastas'];
            $arquivos  += $abaixo['arquivos'];
        }

        return ['subpastas' => $subpastas, 'arquivos' => $arquivos];
    }
```

- [ ] **Step 4: Rodar os testes**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter PastaSecaoRepositoryTest
```

Esperado: PASS (4 testes).

- [ ] **Step 5: Commit**

```bash
git add app/src/Pasta/Repository/PastaSecaoRepository.php app/tests/Pasta/Functional/PastaSecaoRepositoryTest.php
git commit -m "ordena secao entre irmas e conta a arvore para o aviso de exclusao"
```

---

### Task 3: Criar seção dentro de outra

**Files:**
- Modify: `app/src/Pasta/UseCase/CriarPastaSecaoUseCase.php`
- Test: `app/tests/Pasta/Unit/CriarPastaSecaoUseCaseTest.php:1-` (acrescentar casos)

**Interfaces:**
- Consumes: `proximaOrdem(..., ?PastaSecao $pai)` (Task 2), `getProfundidade()` (Task 1)
- Produces: `CriarPastaSecaoUseCase::executar(Pasta $pasta, User $autor, string $nome, Tenant $tenant, ?PastaSecao $pai = null): PastaSecao`
- Produces: `CriarPastaSecaoUseCase::PROFUNDIDADE_MAXIMA = 10`

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar em `app/tests/Pasta/Unit/CriarPastaSecaoUseCaseTest.php`:

```php
    public function testCriarDentroDeOutraGuardaOPai(): void
    {
        $pai = (new PastaSecao())->setNome('PAI');
        $pai->setPasta($this->pasta);
        $pai->setTenant($this->tenant);

        $this->repo->method('proximaOrdem')->willReturn(1);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $secao = $this->useCase->executar($this->pasta, $this->autor, 'Filha', $this->tenant, $pai);

        self::assertSame($pai, $secao->getPai());
        self::assertSame(2, $secao->getProfundidade());
    }

    public function testRecusaPaiDeOutraPasta(): void
    {
        $outraPasta = new Pasta();
        $pai = (new PastaSecao())->setNome('PAI');
        $pai->setPasta($outraPasta);
        $pai->setTenant($this->tenant);

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->pasta, $this->autor, 'Filha', $this->tenant, $pai);
    }

    public function testRecusaPaiDeOutroTenant(): void
    {
        $pai = (new PastaSecao())->setNome('PAI');
        $pai->setPasta($this->pasta);
        $pai->setTenant(new Tenant());

        $this->expectException(AccessDeniedException::class);
        $this->useCase->executar($this->pasta, $this->autor, 'Filha', $this->tenant, $pai);
    }

    public function testRecusaCriarNoNivelOnze(): void
    {
        // cadeia de 10: criar dentro do 10º daria nível 11
        $atual = null;
        for ($i = 0; $i < 10; ++$i) {
            $novo = (new PastaSecao())->setNome('N' . $i)->setPai($atual);
            $novo->setPasta($this->pasta);
            $novo->setTenant($this->tenant);
            $atual = $novo;
        }
        self::assertSame(10, $atual->getProfundidade(), 'sanidade do arranjo do teste');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('10 níveis');
        $this->useCase->executar($this->pasta, $this->autor, 'Estoura', $this->tenant, $atual);
    }

    public function testAceitaCriarNoNivelDez(): void
    {
        $atual = null;
        for ($i = 0; $i < 9; ++$i) {
            $novo = (new PastaSecao())->setNome('N' . $i)->setPai($atual);
            $novo->setPasta($this->pasta);
            $novo->setTenant($this->tenant);
            $atual = $novo;
        }

        $this->repo->method('proximaOrdem')->willReturn(1);
        $secao = $this->useCase->executar($this->pasta, $this->autor, 'Décima', $this->tenant, $atual);

        self::assertSame(10, $secao->getProfundidade());
    }
```

Acrescentar os `use` que faltarem no topo do arquivo:

```php
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
```

- [ ] **Step 2: Rodar para confirmar que falha**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter CriarPastaSecaoUseCaseTest
```

Esperado: FAIL — `executar()` não aceita o 5º argumento.

- [ ] **Step 3: Implementar**

Substituir o `executar` de `app/src/Pasta/UseCase/CriarPastaSecaoUseCase.php`:

```php
    /** Teto de profundidade da árvore. Seção sem pai está no nível 1; o nível 11 é recusado. */
    public const PROFUNDIDADE_MAXIMA = 10;

    public function executar(
        Pasta $pasta,
        User $autor,
        string $nome,
        Tenant $tenant,
        ?PastaSecao $pai = null,
    ): PastaSecao {
        $nome = trim($nome);

        if ($nome === '') {
            throw new \InvalidArgumentException('O nome da seção não pode ser vazio.');
        }

        if (mb_strlen($nome) > 255) {
            throw new \InvalidArgumentException('O nome da seção deve ter no máximo 255 caracteres.');
        }

        if ($pai !== null) {
            if ($pai->getTenant() !== $tenant) {
                throw new AccessDeniedException('Pasta de destino não pertence ao tenant do usuário.');
            }

            if ($pai->getPasta() !== $pasta) {
                throw new \InvalidArgumentException('A pasta de destino não pertence à mesma pasta.');
            }

            if ($pai->getProfundidade() >= self::PROFUNDIDADE_MAXIMA) {
                throw new \InvalidArgumentException(
                    sprintf('Não é possível passar de %d níveis de pasta.', self::PROFUNDIDADE_MAXIMA),
                );
            }
        }

        $ordem = $this->secaoRepository->proximaOrdem($pasta, $tenant, $pai);

        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome($nome);
        $secao->setOrdem($ordem);
        $secao->setPai($pai);

        $this->em->persist($secao);
        $this->em->flush();

        return $secao;
    }
```

Acrescentar o `use`:

```php
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
```

- [ ] **Step 4: Rodar os testes**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter CriarPastaSecaoUseCaseTest
```

Esperado: PASS (todos, os antigos inclusive — a assinatura é retrocompatível).

- [ ] **Step 5: Provar os guards por reintrodução**

Comente a checagem de profundidade no UseCase e rode de novo. O `testRecusaCriarNoNivelOnze` **tem que ficar vermelho**. Se ficar verde, o teste não prova nada — conserte o teste. Descomente depois.

- [ ] **Step 6: Commit**

```bash
git add app/src/Pasta/UseCase/CriarPastaSecaoUseCase.php app/tests/Pasta/Unit/CriarPastaSecaoUseCaseTest.php
git commit -m "cria pasta dentro de pasta, com teto de dez niveis"
```

---

### Task 4: Mover pasta

**Files:**
- Create: `app/src/Pasta/UseCase/MoverPastaSecaoUseCase.php`
- Test: `app/tests/Pasta/Unit/MoverPastaSecaoUseCaseTest.php`

**Interfaces:**
- Consumes: `descendeDe()`, `getProfundidade()`, `getAltura()` (Task 1); `proximaOrdem(..., ?PastaSecao)` (Task 2); `CriarPastaSecaoUseCase::PROFUNDIDADE_MAXIMA` (Task 3)
- Produces: `MoverPastaSecaoUseCase::executar(PastaSecao $secao, ?PastaSecao $destino, User $autor, Tenant $tenant): void`

- [ ] **Step 1: Escrever os testes que falham**

Criar `app/tests/Pasta/Unit/MoverPastaSecaoUseCaseTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\UseCase\MoverPastaSecaoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(MoverPastaSecaoUseCase::class)]
final class MoverPastaSecaoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PastaSecaoRepository&MockObject $repo;
    private MoverPastaSecaoUseCase $useCase;
    private Pasta $pasta;
    private User $autor;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->repo    = $this->createMock(PastaSecaoRepository::class);
        $this->useCase = new MoverPastaSecaoUseCase($this->em, $this->repo);

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com');
        $this->pasta  = new Pasta();
    }

    private function secao(string $nome, ?PastaSecao $pai = null): PastaSecao
    {
        // NÃO acrescente $pai->getFilhas()->add($s) aqui: desde o fix da Task 1, setPai() já
        // sincroniza os dois lados da associação, e o add() manual DUPLICARIA a entrada.
        $s = (new PastaSecao())->setNome($nome)->setPai($pai);
        $s->setPasta($this->pasta);
        $s->setTenant($this->tenant);

        return $s;
    }

    public function testMoveParaDentroDeOutra(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B');

        $this->repo->method('proximaOrdem')->willReturn(1);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($b, $a, $this->autor, $this->tenant);

        self::assertSame($a, $b->getPai());
        self::assertSame(1, $b->getOrdem());
        self::assertTrue($a->getFilhas()->contains($b), 'entrou nas filhas do destino');
    }

    public function testMoverEntreDoisPaisTiraDoAntigo(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B');
        $x = $this->secao('X', $a);

        $this->repo->method('proximaOrdem')->willReturn(1);

        $this->useCase->executar($x, $b, $this->autor, $this->tenant);

        self::assertSame($b, $x->getPai());
        self::assertTrue($b->getFilhas()->contains($x), 'entrou nas filhas do novo pai');
        self::assertFalse(
            $a->getFilhas()->contains($x),
            'saiu das filhas do pai antigo — senão getAltura() de A fica inflada para sempre',
        );
        self::assertSame(1, $a->getAltura(), 'A voltou a ser folha');
    }

    public function testMoveDeVoltaParaARaiz(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B', $a);

        $this->repo->method('proximaOrdem')->willReturn(3);

        $this->useCase->executar($b, null, $this->autor, $this->tenant);

        self::assertNull($b->getPai());
        self::assertSame(3, $b->getOrdem());
        self::assertFalse($a->getFilhas()->contains($b), 'saiu das filhas do pai antigo');
    }

    public function testRecusaMoverParaDentroDaPropriaFilha(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B', $a);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dentro dela mesma');
        $this->useCase->executar($a, $b, $this->autor, $this->tenant);
    }

    public function testRecusaMoverParaDentroDaPropriaNeta(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B', $a);
        $c = $this->secao('C', $b);

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($a, $c, $this->autor, $this->tenant);
    }

    public function testRecusaMoverParaSiMesma(): void
    {
        $a = $this->secao('A');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($a, $a, $this->autor, $this->tenant);
    }

    public function testRecusaQuandoASubarvoreEstouraOTeto(): void
    {
        // Borda exata do lado de fora: destino no nível 7 recebendo subárvore de altura 4.
        // A raiz da subárvore cairia no nível 8 e a folha dela no 11 — um a mais que o teto.
        $destino = null;
        for ($i = 0; $i < 7; ++$i) {
            $destino = $this->secao('N' . $i, $destino);
        }
        self::assertSame(7, $destino->getProfundidade(), 'sanidade do arranjo');

        $r = $this->secao('R');
        $x = $this->secao('X', $r);
        $y = $this->secao('Y', $x);
        $this->secao('Z', $y);
        self::assertSame(4, $r->getAltura(), 'sanidade do arranjo');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('10 níveis');
        $this->useCase->executar($r, $destino, $this->autor, $this->tenant);
    }

    public function testAceitaQuandoASubarvoreCabeExatamente(): void
    {
        // Borda exata do lado de dentro: destino no nível 6 + altura 4 → folha no nível 10.
        // 6 + 4 = 10, e o guard só recusa acima de 10.
        $destino = null;
        for ($i = 0; $i < 6; ++$i) {
            $destino = $this->secao('N' . $i, $destino);
        }
        self::assertSame(6, $destino->getProfundidade(), 'sanidade do arranjo');

        $r = $this->secao('R');
        $x = $this->secao('X', $r);
        $y = $this->secao('Y', $x);
        $this->secao('Z', $y);
        self::assertSame(4, $r->getAltura(), 'sanidade do arranjo');

        $this->repo->method('proximaOrdem')->willReturn(1);
        $this->useCase->executar($r, $destino, $this->autor, $this->tenant);

        self::assertSame($destino, $r->getPai());
    }

    public function testRecusaDestinoDeOutroTenant(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B');
        $b->setTenant(new Tenant());

        $this->expectException(AccessDeniedException::class);
        $this->useCase->executar($a, $b, $this->autor, $this->tenant);
    }

    public function testRecusaSecaoDeOutroTenant(): void
    {
        $a = $this->secao('A');
        $a->setTenant(new Tenant());

        $this->expectException(AccessDeniedException::class);
        $this->useCase->executar($a, null, $this->autor, $this->tenant);
    }

    public function testRecusaDestinoDeOutraPasta(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B');
        $b->setPasta(new Pasta());

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($a, $b, $this->autor, $this->tenant);
    }
}
```

- [ ] **Step 2: Rodar para confirmar que falha**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter MoverPastaSecaoUseCaseTest
```

Esperado: FAIL — classe `MoverPastaSecaoUseCase` não existe.

- [ ] **Step 3: Implementar**

Criar `app/src/Pasta/UseCase/MoverPastaSecaoUseCase.php`:

```php
<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaSecaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Move uma pasta para dentro de outra, ou de volta para a raiz da pasta ($destino = null).
 *
 * Os dois guards daqui não são zelo: sem o de ciclo a pasta some da árvore (ninguém a alcança a
 * partir da raiz) e, quando o espelho com o Drive entrar (Entrega 2), a travessia vira laço
 * infinito contra a API real. O de profundidade valida a SUBÁRVORE inteira — validar só o nó
 * movido deixa passar uma árvore de 4 níveis indo parar no nível 8.
 */
final class MoverPastaSecaoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaSecaoRepository $secaoRepository,
    ) {
    }

    public function executar(PastaSecao $secao, ?PastaSecao $destino, User $autor, Tenant $tenant): void
    {
        if ($secao->getTenant() !== $tenant) {
            throw new AccessDeniedException('Seção não pertence ao tenant do usuário.');
        }

        $pasta = $secao->getPasta();
        if ($pasta === null) {
            throw new \LogicException('Seção sem pasta associada.');
        }

        if ($destino !== null) {
            if ($destino->getTenant() !== $tenant) {
                throw new AccessDeniedException('Pasta de destino não pertence ao tenant do usuário.');
            }

            if ($destino->getPasta() !== $pasta) {
                throw new \InvalidArgumentException('A pasta de destino não pertence à mesma pasta.');
            }

            if ($destino === $secao || $destino->descendeDe($secao)) {
                throw new \InvalidArgumentException('Não é possível mover uma pasta para dentro dela mesma.');
            }

            if ($destino->getProfundidade() + $secao->getAltura() > CriarPastaSecaoUseCase::PROFUNDIDADE_MAXIMA) {
                throw new \InvalidArgumentException(
                    sprintf('Não é possível passar de %d níveis de pasta.', CriarPastaSecaoUseCase::PROFUNDIDADE_MAXIMA),
                );
            }
        }

        $secao->setPai($destino);
        $secao->setOrdem($this->secaoRepository->proximaOrdem($pasta, $tenant, $destino));

        $this->em->flush();
    }
}
```

- [ ] **Step 4: Rodar os testes**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter MoverPastaSecaoUseCaseTest
```

Esperado: PASS (10 testes).

- [ ] **Step 5: Provar os dois guards por reintrodução**

Comente o guard de ciclo → `testRecusaMoverParaDentroDaPropriaNeta` tem que ficar vermelho. Descomente. Comente o de profundidade → `testRecusaQuandoASubarvoreEstouraOTeto` tem que ficar vermelho. Descomente. Se algum ficar verde com o guard fora, o teste não prova nada.

- [ ] **Step 6: Commit**

```bash
git add app/src/Pasta/UseCase/MoverPastaSecaoUseCase.php app/tests/Pasta/Unit/MoverPastaSecaoUseCaseTest.php
git commit -m "move pasta entre niveis, barrando ciclo e estouro de profundidade"
```

---

### Task 5: Reordenar entre irmãs

**Files:**
- Modify: `app/src/Pasta/UseCase/ReordenarSecoesUseCase.php:22-45`
- Test: `app/tests/Pasta/Unit/ReordenarSecoesUseCaseTest.php` — ⚠️ **JÁ EXISTE, com 3 testes.** Esta tarefa **acrescenta** um teste e estende um helper; **não** recria o arquivo.

**Interfaces:**
- Consumes: `PastaSecao::getPai()` (Task 1)
- Produces: `ReordenarSecoesUseCase::executar(Pasta $pasta, Tenant $tenant, array $idsOrdenados): void` — **assinatura inalterada**; o que muda é que a numeração reinicia por grupo de irmãs

- [ ] **Step 1: Escrever o teste que falha**

⚠️ **O arquivo já existe** com 3 testes (`testReordenarAtualizaOrdemCorretamente`,
`testArrayVazioNaoChamaFlush`, `testIdInvalidoIgnoradoSilenciosamente`) e um helper
`criarSecao(int $id, int $ordem)`. **Não recrie o arquivo — os 3 testes têm de sobreviver.** Eles
usam só seções de raiz, então continuam válidos com a numeração por irmãs.

Primeiro, **estenda o helper existente** com um terceiro parâmetro opcional:

```php
    private function criarSecao(int $id, int $ordem, ?PastaSecao $pai = null): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setOrdem($ordem);
        $secao->setPai($pai);

        $ref = new \ReflectionProperty(PastaSecao::class, 'id');
        $ref->setValue($secao, $id);

        return $secao;
    }
```

As 3 chamadas existentes passam 2 argumentos e continuam funcionando sem alteração.

Depois **acrescente** este teste ao final da classe:

```php
    public function testNumeracaoReiniciaEmCadaGrupoDeIrmas(): void
    {
        $a  = $this->criarSecao(1, 1);
        $b  = $this->criarSecao(2, 2);
        $a1 = $this->criarSecao(3, 1, $a);
        $a2 = $this->criarSecao(4, 2, $a);

        $this->repo->method('findByPasta')->willReturn([$a, $b, $a1, $a2]);
        $this->em->expects($this->once())->method('flush');

        // ordem pedida: B antes de A na raiz; A2 antes de A1 dentro de A
        $this->useCase->executar($this->pasta, $this->tenant, [2, 1, 4, 3]);

        self::assertSame(1, $b->getOrdem(), 'B é a 1ª da RAIZ');
        self::assertSame(2, $a->getOrdem(), 'A é a 2ª da RAIZ');
        self::assertSame(1, $a2->getOrdem(), 'A2 é a 1ª DENTRO de A — a numeração reinicia');
        self::assertSame(2, $a1->getOrdem(), 'A1 é a 2ª DENTRO de A');
    }
```

- [ ] **Step 2: Rodar para confirmar que falha**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter ReordenarSecoesUseCaseTest
```

Esperado: FAIL — hoje a numeração é global, então `$a2->getOrdem()` volta 3, não 1.

- [ ] **Step 3: Implementar**

Substituir o corpo do `executar` em `app/src/Pasta/UseCase/ReordenarSecoesUseCase.php`:

```php
    /** @param int[] $idsOrdenados IDs das seções na nova ordem desejada */
    public function executar(Pasta $pasta, Tenant $tenant, array $idsOrdenados): void
    {
        if ($idsOrdenados === []) {
            return;
        }

        $secoes = $this->secaoRepository->findByPasta($pasta, $tenant);

        $mapa = [];
        foreach ($secoes as $secao) {
            $mapa[$secao->getId()] = $secao;
        }

        // A ordem é POSIÇÃO ENTRE IRMÃS: cada grupo de mesmo pai numera do 1. Um contador global
        // faria a 1ª subpasta de um pai nascer com a ordem do fim da lista da pasta inteira.
        $proxima = [];
        foreach ($idsOrdenados as $id) {
            $id = (int) $id;
            if (!isset($mapa[$id])) {
                continue;
            }
            $secao = $mapa[$id];
            $chave = (string) ($secao->getPai()?->getId() ?? 'raiz');
            $proxima[$chave] ??= 1;
            $secao->setOrdem($proxima[$chave]);
            ++$proxima[$chave];
        }

        $this->em->flush();
    }
```

- [ ] **Step 4: Rodar os testes**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter ReordenarSecoesUseCaseTest
```

Esperado: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/src/Pasta/UseCase/ReordenarSecoesUseCase.php app/tests/Pasta/Unit/ReordenarSecoesUseCaseTest.php
git commit -m "reordena secao dentro do grupo de irmas, nao na pasta inteira"
```

---

### Task 6: Controller

**Files:**
- Modify: `app/src/Pasta/Controller/PastaSecaoController.php:48-80` (criar) e acrescentar a rota de mover
- Test: `app/tests/Pasta/Functional/PastaSecaoControllerTest.php` (acrescentar casos)

**Interfaces:**
- Consumes: `CriarPastaSecaoUseCase::executar(..., ?PastaSecao $pai)` (Task 3), `MoverPastaSecaoUseCase::executar()` (Task 4), `contarConteudoRecursivo()` (Task 2)
- Produces:
  - rota `pasta_secao_mover` → `POST /pasta/secao/{secaoId}/mover`, campo `destinoId` (vazio = raiz), token CSRF `pasta_secao_mover_{secaoId}`
  - `pasta_secao_criar` passa a aceitar o campo opcional `paiId`
  - a resposta de `pasta_secao_criar` ganha `paiId` e `csrfMover`

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar em `app/tests/Pasta/Functional/PastaSecaoControllerTest.php`:

```php
    #[TestDox('criar aceita paiId e aninha a pasta')]
    public function testCriarComPaiAninha(): void
    {
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta = $this->criarPasta($tenant);
        $pai   = $this->criarSecao($pasta, $tenant);

        $this->client->loginUser($user);
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/secao', [
            '_token' => $this->csrf('pasta_secao_criar_' . $pasta->getId()),
            'nome'   => 'Filha',
            'paiId'  => (string) $pai->getId(),
        ]);

        self::assertResponseStatusCodeSame(201);
        $json = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($pai->getId(), $json['paiId']);
    }

    #[TestDox('mover recusa destino de outro tenant')]
    public function testMoverRecusaDestinoDeOutroTenant(): void
    {
        [$user, $tenant]   = $this->criarUsuarioAdmin();
        [, $outroTenant]   = $this->criarUsuarioAdmin();
        $pasta      = $this->criarPasta($tenant);
        $secao      = $this->criarSecao($pasta, $tenant);
        $outraPasta = $this->criarPasta($outroTenant);
        $alheia     = $this->criarSecao($outraPasta, $outroTenant);

        $this->client->loginUser($user);
        $this->client->request('POST', '/pasta/secao/' . $secao->getId() . '/mover', [
            '_token'    => $this->csrf('pasta_secao_mover_' . $secao->getId()),
            'destinoId' => (string) $alheia->getId(),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('mover sem destinoId devolve a pasta para a raiz')]
    public function testMoverParaRaiz(): void
    {
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta = $this->criarPasta($tenant);
        $pai   = $this->criarSecao($pasta, $tenant);

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $filha  = new PastaSecao();
        $filha->setPasta($pasta);
        $filha->setTenant($tenant);
        $filha->setNome('FILHA');
        $filha->setOrdem(1);
        $filha->setPai($pai);
        $em->persist($filha);
        $em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/pasta/secao/' . $filha->getId() . '/mover', [
            '_token'    => $this->csrf('pasta_secao_mover_' . $filha->getId()),
            'destinoId' => '',
        ]);

        self::assertResponseIsSuccessful();
        $em->refresh($filha);
        self::assertNull($filha->getPai());
    }

    #[TestDox('mover para dentro da própria filha é recusado mesmo com a requisição forjada')]
    public function testMoverParaDescendenteRecusadoPelaRota(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $mae = $this->criarSecao($pasta, $tenant);

        $filha = new PastaSecao();
        $filha->setPasta($pasta);
        $filha->setTenant($tenant);
        $filha->setNome('FILHA');
        $filha->setOrdem(1);
        $filha->setPai($mae);
        $em->persist($filha);
        $em->flush();

        // A tela esconderia a filha da lista de destinos. Aqui mandamos direto, como faria
        // qualquer pessoa com o DevTools aberto — o back tem de recusar sozinho.
        $client->request('POST', '/pasta/secao/' . $mae->getId() . '/mover', [
            '_token'    => $this->csrf('pasta_secao_mover_' . $mae->getId()),
            'destinoId' => (string) $filha->getId(),
        ]);

        self::assertResponseStatusCodeSame(422);

        $maeId = $mae->getId();
        $em->clear();
        self::assertNull(
            $em->find(PastaSecao::class, $maeId)->getPai(),
            'a mãe continua na raiz — a recusa não pode ter gravado nada',
        );
    }

    #[TestDox('criar acima do 10º nível é recusado mesmo com a requisição forjada')]
    public function testCriarAcimaDoTetoRecusadoPelaRota(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Cadeia de 10: a última fica no nível 10, então criar dentro dela daria 11.
        $anterior = null;
        for ($i = 0; $i < 10; ++$i) {
            $s = new PastaSecao();
            $s->setPasta($pasta);
            $s->setTenant($tenant);
            $s->setNome('N' . $i);
            $s->setOrdem(1);
            $s->setPai($anterior);
            $em->persist($s);
            $anterior = $s;
        }
        $em->flush();
        $paiId = $anterior->getId();

        // O teto mora SÓ no back — a tela nem sabe qual é o número. Mandamos direto.
        $client->request('POST', '/pasta/' . $pasta->getId() . '/secao', [
            '_token' => $this->csrf('pasta_secao_criar_' . $pasta->getId()),
            'nome'   => 'Decima primeira',
            'paiId'  => (string) $paiId,
        ]);

        self::assertResponseStatusCodeSame(422);

        $em->clear();
        $criadas = $em->getRepository(PastaSecao::class)->count(['pasta' => $pasta->getId()]);
        self::assertSame(10, $criadas, 'nenhuma seção a mais foi gravada');
    }

    #[TestDox('excluir informa quanto conteúdo foi apagado junto')]
    public function testExcluirDevolveAContagem(): void
    {
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta = $this->criarPasta($tenant);
        $pai   = $this->criarSecao($pasta, $tenant);

        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $filha = new PastaSecao();
        $filha->setPasta($pasta);
        $filha->setTenant($tenant);
        $filha->setNome('FILHA');
        $filha->setOrdem(1);
        $filha->setPai($pai);
        $em->persist($filha);
        $em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/pasta/secao/' . $pai->getId() . '/excluir', [
            '_token' => $this->csrf('pasta_secao_excluir_' . $pai->getId()),
        ]);

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $json['subpastasRemovidas']);
    }
```

> 🔒 **Os dois testes `...RecusadoPelaRota` são a prova de que o front não é a autoridade.** Todo
> guard desta frente vive no UseCase, e o JavaScript só esconde opções por conforto. Estes testes
> atacam pela rota HTTP **ignorando a tela** — é o cenário real de alguém manipulando a requisição.
> Sem eles, o guard de ciclo e o teto de 10 níveis só estariam provados contra objetos em memória,
> nunca contra o caminho que um atacante usaria. **Não os transforme em teste de UseCase.**

> ⚠️ **`$this->client` NÃO EXISTE nesta classe** — conferido no arquivo real. O padrão da casa cria o
> client como variável local e monta o contexto em quatro linhas, nesta ordem:
>
> ```php
>         $client          = static::createClient();
>         [$user, $tenant] = $this->criarUsuarioAdmin();
>         $pasta           = $this->criarPasta($tenant);
>         $this->instalarCsrfStorage();                    // sem isto, $this->csrf() não casa
>         $this->logarComTenant($client, $user, $tenant);  // faz login E aceita os termos
> ```
>
> Reescreva os quatro testes acima nesse formato, trocando todo `$this->client` por `$client`.
> `$this->csrf(...)`, `criarUsuarioAdmin()`, `criarPasta()` e `criarSecao()` já existem — use-os,
> **não** crie helpers paralelos. `instalarCsrfStorage()` e `logarComTenant()` também já existem.

- [ ] **Step 2: Rodar para confirmar que falha**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter PastaSecaoControllerTest
```

Esperado: FAIL — rota `pasta_secao_mover` não existe; `paiId` ignorado.

- [ ] **Step 3: Implementar**

Em `app/src/Pasta/Controller/PastaSecaoController.php`, injetar **duas** dependências novas no
construtor (`app/src/Pasta/Controller/PastaSecaoController.php:32-45`):

```php
        private readonly MoverPastaSecaoUseCase $moverPastaUseCase,
        private readonly PastaSecaoRepository $secaoRepository,
```

⚠️ **O `PastaSecaoRepository` NÃO está injetado hoje** — conferido no construtor atual, que vai de
`EntityManagerInterface` até `ReordenarSecoesUseCase` e não o inclui. Sem essa linha, os dois
`$this->secaoRepository->findByIdAndPastaAndTenant(...)` abaixo quebram com erro de propriedade
indefinida. Acrescentar também o `use App\Pasta\Repository\PastaSecaoRepository;` no topo.

No método `criar`, entre a validação de CSRF e a chamada do UseCase:

```php
        $nome  = trim((string) $request->request->get('nome', ''));
        $paiId = (int) $request->request->get('paiId', 0);

        $pai = null;
        if ($paiId > 0) {
            $pai = $this->secaoRepository->findByIdAndPastaAndTenant($paiId, $pasta, $tenant);
            if ($pai === null) {
                return $this->json(['erro' => 'Pasta de destino não encontrada.'], Response::HTTP_NOT_FOUND);
            }
        }

        try {
            $secao = $this->criarUseCase->executar($pasta, $currentUser, $nome, $tenant, $pai);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException $e) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }
```

E na resposta do `criar`, acrescentar duas chaves:

```php
            'paiId'     => $pai?->getId(),
            'csrfMover' => $this->csrfTokenManager->getToken('pasta_secao_mover_' . $secao->getId())->getValue(),
```

Acrescentar a rota de mover (o guard de permissão e o padrão de CSRF são copiados de `renomear`, que já está no arquivo):

```php
    #[Route('/secao/{secaoId}/mover', name: 'pasta_secao_mover', methods: ['POST'])]
    public function mover(int $secaoId, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();

        $secao = $this->em->find(PastaSecao::class, $secaoId);
        if ($secao === null) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $pasta   = $secao->getPasta();
        $pastaId = (int) $pasta?->getId();
        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão para editar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_secao_mover_' . $secaoId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $destinoId = (int) $request->request->get('destinoId', 0);
        $destino   = null;
        if ($destinoId > 0) {
            // Busca escopada por pasta+tenant: é o guard IDOR. Um em->find() por id cru aceitaria
            // o id de uma seção de outro escritório e só o UseCase pegaria.
            $destino = $this->secaoRepository->findByIdAndPastaAndTenant($destinoId, $pasta, $tenant);
            if ($destino === null) {
                return $this->json(['erro' => 'Pasta de destino não encontrada.'], Response::HTTP_FORBIDDEN);
            }
        }

        try {
            $this->moverPastaUseCase->executar($secao, $destino, $currentUser, $tenant);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException $e) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['ok' => true, 'paiId' => $destino?->getId()]);
    }
```

No método `excluir`, **antes** de chamar o UseCase, capturar a contagem (depois da exclusão a árvore não existe mais):

```php
        $conteudo = $this->secaoRepository->contarConteudoRecursivo($secao);
```

🔴 **E corrigir um vazamento que a árvore introduz.** O loop de limpeza de disco que já está no método
percorre **só** `$secao->getDocumentos()` — os documentos diretos. Isso bastava quando só existia um
nível. Com a árvore, apagar uma pasta remove do **banco**, em cascata, os documentos de filhas e
netas — mas os **arquivos físicos delas ficam órfãos no disco para sempre**, porque o loop nunca as
alcança. Substitua o loop atual por uma varredura da árvore inteira:

```php
        // A limpeza tem de percorrer a ÁRVORE, não só os documentos diretos: o cascade do banco
        // apaga as linhas de toda a descendência, e sem isto os arquivos das filhas e netas ficam
        // órfãos no disco. Antes das pastas aninhadas o loop raso bastava, porque não havia netas.
        $this->limparArquivosDaArvore($secao);
```

E o método privado, no fim da classe:

```php
    /** Remove do disco os arquivos de $secao e de toda a descendência dela. */
    private function limparArquivosDaArvore(PastaSecao $secao): void
    {
        foreach ($secao->getDocumentos() as $doc) {
            $caminho = $this->storage->caminho($this->uploadsDir, $doc->getCaminhoArquivo());
            if ($this->storage->existe($caminho)) {
                $this->storage->excluir($caminho);
            }
        }

        foreach ($secao->getFilhas() as $filha) {
            $this->limparArquivosDaArvore($filha);
        }
    }
```

E devolver na resposta:

```php
        return $this->json([
            'ok'                  => true,
            'subpastasRemovidas'  => $conteudo['subpastas'],
            'arquivosRemovidos'   => $conteudo['arquivos'],
        ]);
```

- [ ] **Step 4: Rodar os testes**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter PastaSecaoControllerTest
```

Esperado: PASS (novos e antigos).

- [ ] **Step 5: Conferir que a rota existe de verdade**

```bash
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/pasta-subpastas-aninhadas/app && php bin/console debug:router pasta_secao_mover'
```

- [ ] **Step 6: Commit**

```bash
git add app/src/Pasta/Controller/PastaSecaoController.php app/tests/Pasta/Functional/PastaSecaoControllerTest.php
git commit -m "expoe criar-dentro-de e mover pasta pelo controller"
```

---

### Task 7: Template

**Files:**
- Modify: `app/templates/pasta/show.html.twig:906-931` (bloco `fm-pasta`)
- Test: `app/tests/Pasta/Functional/PastaShowDocumentosControllerTest.php` (acrescentar caso)

**Interfaces:**
- Consumes: `PastaSecao::getPai()` (Task 1); rota `pasta_secao_mover` (Task 6)
- Produces: cada `.fm-pasta` carrega `data-pai-id` (vazio = raiz), `data-url-mover`, `data-csrf-mover`; o container `#fileManager` carrega `data-arvore="1"`

- [ ] **Step 1: Escrever o teste que falha**

Acrescentar em `app/tests/Pasta/Functional/PastaShowDocumentosControllerTest.php`:

```php
    #[TestDox('a subpasta chega ao HTML com o pai declarado')]
    public function testSubpastaTrazPaiNoHtml(): void
    {
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta = $this->criarPasta($tenant);

        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $pai = new PastaSecao();
        $pai->setPasta($pasta);
        $pai->setTenant($tenant);
        $pai->setNome('PAI');
        $pai->setOrdem(1);
        $em->persist($pai);

        $filha = new PastaSecao();
        $filha->setPasta($pasta);
        $filha->setTenant($tenant);
        $filha->setNome('FILHA');
        $filha->setOrdem(1);
        $filha->setPai($pai);
        $em->persist($filha);
        $em->flush();

        $client = static::createClient();
        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();

        $noPai = $crawler->filter('.fm-pasta[data-secao-id="' . $pai->getId() . '"]');
        self::assertSame('', $noPai->attr('data-pai-id'), 'a pasta de topo não tem pai');

        $noFilha = $crawler->filter('.fm-pasta[data-secao-id="' . $filha->getId() . '"]');
        self::assertSame((string) $pai->getId(), $noFilha->attr('data-pai-id'));
    }
```

- [ ] **Step 2: Rodar para confirmar que falha**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter PastaShowDocumentosControllerTest
```

Esperado: FAIL — `data-pai-id` não existe no HTML.

- [ ] **Step 3: Implementar**

Em `app/templates/pasta/show.html.twig`, no `<div class="fm-pasta" ...>`, acrescentar três atributos:

```twig
                                                         data-pai-id="{{ secao.pai ? secao.pai.id : '' }}"
                                                         data-url-mover="{{ path('pasta_secao_mover', {secaoId: secao.id}) }}"
                                                         data-csrf-mover="{{ csrf_token('pasta_secao_mover_' ~ secao.id) }}"
```

No mesmo bloco, dentro do `<ul class="dropdown-menu ...">`, acrescentar o item de mover logo após "Renomear":

```twig
                                                                <li><button class="dropdown-item fm-pasta-mover" type="button"><i class="bi bi-folder-symlink me-2"></i>Mover para...</button></li>
```

E no `<div id="fileManager" ...>`, acrescentar o sinalizador que a Cobrança **não** vai declarar:

```twig
                                     data-arvore="1"
```

- [ ] **Step 4: Rodar os testes**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter PastaShowDocumentosControllerTest
```

Esperado: PASS.

- [ ] **Step 5: Lint do Twig**

```bash
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/pasta-subpastas-aninhadas/app && php bin/console lint:twig templates'
```

- [ ] **Step 6: Commit**

```bash
git add app/templates/pasta/show.html.twig app/tests/Pasta/Functional/PastaShowDocumentosControllerTest.php
git commit -m "leva o pai da pasta e a acao de mover para o HTML"
```

---

### Task 8: JavaScript do gerenciador

**Files:**
- Modify: `app/public/js/pasta-arquivos.js` (navegação, breadcrumb, criar-em, mover, busca)
- Modify: `app/templates/pasta/show.html.twig` — esta tarefa **também** mexe no template: acrescenta o
  modal `#fmDestinoModal`, o atributo `data-url-mover-tpl` no `#fileManager`, o `<span class="fm-arq-local">`
  em cada arquivo e a regra CSS `.fm-pasta-alvo`. Onde os passos abaixo dizem "no template", é aqui.

**Interfaces:**
- Consumes: `data-pai-id`, `data-url-mover`, `data-csrf-mover`, `data-arvore` (Task 7); rota de mover (Task 6)

> ⚠️ **Esta tarefa não tem teste automatizado.** O projeto não tem suíte de JS — só PHPUnit (que lê HTML) e Playwright E2E (que é do dono). O que dá para provar aqui é que a página não quebra e que o HTML continua correto, o que as Tasks 6 e 7 já cobrem. **O comportamento desta tarefa vai inteiro para a lista de smoke** (Task 10).

> ⚠️ **Este arquivo é compartilhado com a Cobrança.** Toda função nova precisa ser inerte quando `fm.dataset.arvore !== '1'`. A Cobrança não declara o atributo, então cai no caminho de um nível.

- [ ] **Step 1: Estado da navegação vira caminho, não id solto**

Substituir a variável de estado e as funções de navegação:

```js
    // Estado
    let caminho = [];           // [] = raiz; senão a cadeia de ids até a pasta aberta
    const temArvore = fm.dataset.arvore === '1';
```

```js
    function paiDe(secaoId) {
        const c = elPastas ? elPastas.querySelector('.fm-pasta[data-secao-id="' + secaoId + '"]') : null;
        return c && c.dataset.paiId ? c.dataset.paiId : null;
    }

    function pastaAtualId() { return caminho.length ? caminho[caminho.length - 1] : 'geral'; }

    function entrar(secaoId) {
        if (!temArvore) { caminho = [String(secaoId)]; }
        else {
            // remonta a cadeia inteira subindo pelos pais: entrar por busca ou por link direto
            // precisa produzir o mesmo breadcrumb que entrar clicando nível a nível.
            const cadeia = [];
            let atual = String(secaoId);
            let voltas = 0;
            while (atual && voltas < 100) { cadeia.unshift(atual); atual = paiDe(atual); voltas++; }
            caminho = cadeia;
        }
        termoBusca = '';
        if (elBusca) elBusca.value = '';
        sessionStorage.setItem('fmFolder_' + pastaId, JSON.stringify(caminho));
        render();
    }

    function voltarRaiz() { caminho = []; sessionStorage.setItem('fmFolder_' + pastaId, '[]'); render(); }
```

- [ ] **Step 2: O render mostra as filhas do nível aberto**

No `render()`, trocar o filtro das pastas e dos arquivos:

```js
        const atual = pastaAtualId();
        const naRaiz = caminho.length === 0;

        // pastas visíveis = as filhas do nível aberto
        todasPastas().forEach(function (el) {
            const pai = el.dataset.paiId || '';
            const mostra = !buscando && (naRaiz ? pai === '' : pai === atual);
            el.classList.toggle('fm-oculto', !mostra);
        });
        toggle(elGrupoPastas, !buscando && todasPastas().some(function (el) {
            return !el.classList.contains('fm-oculto');
        }));
```

E o filtro dos arquivos passa a comparar com `atual`:

```js
            const mostra = buscando
                ? (el.dataset.nome || '').toLowerCase().indexOf(termo) !== -1
                : el.dataset.secao === atual;
```

- [ ] **Step 3: Breadcrumb de todos os níveis**

Substituir `mostrarCrumb`/`esconderCrumb` por uma versão que desenha a cadeia:

```js
    function renderCrumb() {
        if (!elCrumbAtual) return;
        if (termoBusca.trim() !== '') {
            elCrumbSep && elCrumbSep.classList.remove('fm-oculto');
            elCrumbAtual.classList.remove('fm-oculto');
            elCrumbAtual.textContent = 'Resultados da busca';
            return;
        }
        if (caminho.length === 0) {
            elCrumbSep && elCrumbSep.classList.add('fm-oculto');
            elCrumbAtual.classList.add('fm-oculto');
            return;
        }
        elCrumbSep && elCrumbSep.classList.remove('fm-oculto');
        elCrumbAtual.classList.remove('fm-oculto');
        elCrumbAtual.innerHTML = caminho.map(function (id, i) {
            const nome = escapeHtml(nomePasta(id));
            return i === caminho.length - 1
                ? '<span>' + nome + '</span>'
                : '<button type="button" class="fm-crumb-nivel btn btn-link p-0 align-baseline" data-nivel="' + i + '">' + nome + '</button>';
        }).join(' <span class="fm-crumb-sep">›</span> ');
    }
```

E ligar o clique nos degraus:

```js
    if (elCrumbAtual) {
        elCrumbAtual.addEventListener('click', function (e) {
            const b = e.target.closest('.fm-crumb-nivel');
            if (!b) return;
            caminho = caminho.slice(0, parseInt(b.dataset.nivel, 10) + 1);
            sessionStorage.setItem('fmFolder_' + pastaId, JSON.stringify(caminho));
            render();
        });
    }
```

- [ ] **Step 4: "Nova pasta" nasce onde você está**

No listener de `#fmNovaPasta` (`pasta-arquivos.js:267-281`), acrescentar o pai ao `FormData`, logo
depois do `fd.append('nome', nome)`:

```js
                if (temArvore && caminho.length) fd.append('paiId', pastaAtualId());
```

E em `adicionarCartaoPasta(secao)` (`pasta-arquivos.js:284`), gravar os três dados novos junto dos
que a função já grava — **sem isso a pasta recém-criada some ao trocar de nível**, porque o render
filtra por `data-pai-id` e o cartão nasceria sem ele:

```js
        div.dataset.paiId = secao.paiId ? String(secao.paiId) : '';
        div.dataset.urlMover = cfg.urlMoverTpl.replace('__ID__', secao.id);
        div.dataset.csrfMover = secao.csrfMover || '';
```

Declarar o template da URL junto dos outros em `cfg` (topo do arquivo):

```js
        urlMoverTpl:         fm.dataset.urlMoverTpl,
```

E no `<div id="fileManager">` do template, acrescentar o atributo correspondente:

```twig
                                     data-url-mover-tpl="{{ path('pasta_secao_mover', {secaoId: '__ID__'}) }}"
```

No `innerHTML` do cartão, acrescentar o item de menu, logo depois do de renomear:

```js
            '<li><button class="dropdown-item fm-pasta-mover" type="button"><i class="bi bi-folder-symlink me-2"></i>Mover para...</button></li>' +
```

- [ ] **Step 5: Mover pelo menu**

```js
    function moverPasta(card, destinoId) {
        const fd = new FormData();
        fd.append('_token', card.dataset.csrfMover);
        fd.append('destinoId', destinoId === null ? '' : String(destinoId));
        return fetch(card.dataset.urlMover, { method: 'POST', body: fd })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || 'Falha ao mover.');
                card.dataset.paiId = res.j.paiId ? String(res.j.paiId) : '';
                render();
            })
            .catch(function (err) { alert(err.message); });
    }
```

Ligar no menu (junto dos outros itens do dropdown, dentro do listener que já existe):

```js
            if (e.target.closest('.fm-pasta-mover')) { escolherDestino(card); return; }
```

E o seletor de destino, montado a partir dos cartões que já estão no DOM — excluindo a própria pasta e a descendência dela:

```js
    function descendentes(secaoId) {
        const filhos = todasPastas().filter(function (el) { return el.dataset.paiId === String(secaoId); });
        return filhos.reduce(function (acc, el) {
            return acc.concat([el.dataset.secaoId], descendentes(el.dataset.secaoId));
        }, []);
    }

    function escolherDestino(card) {
        const proibidos = [card.dataset.secaoId].concat(descendentes(card.dataset.secaoId));
        const opcoes = todasPastas()
            .filter(function (el) { return proibidos.indexOf(el.dataset.secaoId) === -1; })
            .map(function (el) { return { id: el.dataset.secaoId, nome: el.dataset.nome }; });

        pedirDestino('Mover "' + card.dataset.nome + '" para', opcoes).then(function (destinoId) {
            if (destinoId === undefined) return;             // cancelou
            moverPasta(card, destinoId);                      // null = raiz da pasta
        });
    }
```

E o helper do modal, seguindo **exatamente** o padrão de Promise que `pedirTexto()` já usa
(`pasta-arquivos.js:358`), com `<select>` no lugar do campo de texto:

```js
    function pedirDestino(titulo, opcoes) {
        return new Promise(function (resolve) {
            if (!destinoModal) { resolve(undefined); return; }   // sem o modal, a ação não acontece
            destinoResolve = resolve;
            destinoTitulo.textContent = titulo;
            destinoCampo.innerHTML = '<option value="">Raiz da pasta</option>' +
                opcoes.map(function (o) {
                    return '<option value="' + o.id + '">' + escapeHtml(o.nome) + '</option>';
                }).join('');
            destinoModal.show();
        });
    }
```

Acrescentar no template, ao lado do modal de texto que já existe, o modal irmão
`#fmDestinoModal` com `<h5 id="fmDestinoTitulo">`, `<select id="fmDestinoCampo" class="form-select">`
e o botão `#fmDestinoConfirmar`. O confirmar resolve com `destinoCampo.value || null`; o
`hidden.bs.modal` resolve com `undefined` (cancelamento), na mesma forma do `pedirTexto`.

- [ ] **Step 6: Mover arrastando**

⚠️ **Não acrescente `handle` ao Sortable — ele JÁ está lá** (`pasta-arquivos.js:477`,
`handle: '.fm-pasta-grip'`). Arrastar pelo corpo do cartão hoje não faz nada, então o gesto está
livre e não há conflito a resolver. Deixe o bloco do Sortable **intocado**.

O alvo de soltar, no cartão inteiro:

```js
    if (elPastas && temArvore) {
        elPastas.addEventListener('dragover', function (e) {
            const alvo = e.target.closest('.fm-pasta');
            if (alvo && arrastando && alvo !== arrastando) { e.preventDefault(); alvo.classList.add('fm-pasta-alvo'); }
        });
        elPastas.addEventListener('dragleave', function (e) {
            const alvo = e.target.closest('.fm-pasta');
            if (alvo) alvo.classList.remove('fm-pasta-alvo');
        });
        elPastas.addEventListener('drop', function (e) {
            const alvo = e.target.closest('.fm-pasta');
            if (!alvo || !arrastando || alvo === arrastando) return;
            e.preventDefault();
            alvo.classList.remove('fm-pasta-alvo');
            if (descendentes(arrastando.dataset.secaoId).indexOf(alvo.dataset.secaoId) !== -1) {
                alert('Não é possível mover uma pasta para dentro dela mesma.');
                return;
            }
            moverPasta(arrastando, alvo.dataset.secaoId);
        });
    }
```

Declarar `let arrastando = null;` junto do estado, e marcá-lo no `dragstart` do cartão.

Acrescentar o realce do alvo no CSS do template (junto das outras regras `.fm-pasta`):

```css
.fm-pasta-alvo { outline: 2px dashed var(--bs-primary); outline-offset: 2px; }
```

- [ ] **Step 7: A busca diz em que pasta o resultado está**

Com um nível só, um resultado sem endereço era tolerável — a pasta era óbvia. Com árvore, um arquivo
achado 3 níveis abaixo vira um nome solto na tela. No `render()`, quando `buscando` for verdadeiro,
carimbar o caminho em cada resultado visível:

```js
    function caminhoLegivel(secaoId) {
        if (!secaoId || secaoId === 'geral') return 'Documentação Geral';
        const nomes = [];
        let atual = String(secaoId);
        let voltas = 0;
        while (atual && voltas < 100) { nomes.unshift(nomePasta(atual)); atual = paiDe(atual); voltas++; }
        return nomes.join(' › ');
    }
```

E no laço que percorre os arquivos dentro do `render()`, logo depois de decidir `mostra`:

```js
            const badge = el.querySelector('.fm-arq-local');
            if (badge) {
                badge.textContent = buscando ? caminhoLegivel(el.dataset.secao) : '';
                badge.classList.toggle('fm-oculto', !buscando);
            }
```

Acrescentar o elemento no template, dentro de `.fm-arq-principal`, junto de `.fm-arq-sub`:

```twig
                                                                <span class="fm-arq-local fm-oculto text-muted small"></span>
```

- [ ] **Step 8: Conferir que a página carrega sem erro**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter PastaShowDocumentosControllerTest
```

Esperado: PASS — prova que o HTML segue válido. **Não prova o comportamento do JS.**

- [ ] **Step 9: Commit**

```bash
git add app/public/js/pasta-arquivos.js app/templates/pasta/show.html.twig
git commit -m "navega, cria e move pasta em varios niveis no gerenciador"
```

---

### Task 9: Regressão — o sync não pode quebrar

**Files:**
- Test: `app/tests/Sync/Functional/ReconciliadorArvoreNaoRegridTest.php` (criar)

**Interfaces:**
- Consumes: tudo das Tasks 1-4

> Esta é a tarefa que protege a promessa da spec §4: *"não regride nada"*. Uma árvore de 3 níveis no sistema tem de continuar enviando ao Drive achatada, **sem erro**.

- [ ] **Step 1: Escrever o teste**

Criar `app/tests/Sync/Functional/ReconciliadorArvoreNaoRegridTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Shared\Service\ArquivoStorageInterface;
use App\Sync\DTO\ResultadoReconciliacaoPasta;
use App\Sync\Enum\ModoSincronizacao;
use App\Sync\Service\ReconciliadorDePasta;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Sync\Support\FakeGoogleDriveClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Guarda a promessa da §4 da spec de pastas aninhadas: a Entrega 1 NÃO mexe no sync.
 *
 * Uma árvore de 3 níveis no sistema continua subindo ao Drive achatada em UMA subpasta de 1º nível,
 * exatamente como antes de existir hierarquia. Quando a Entrega 2 for implementada, este teste vira
 * vermelho DE PROPÓSITO — é o sinal de que o achatamento saiu, e ele deve ser reescrito, não apagado.
 */
#[CoversClass(ReconciliadorDePasta::class)]
final class ReconciliadorArvoreNaoRegridTest extends KernelTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('árvore de 3 níveis continua subindo ao Drive achatada e sem erro')]
    public function testArvoreProfundaSobeAchatadaSemErro(): void
    {
        $em    = $this->em();
        $pasta = PastaFactory::createOne(['driveFolderId' => 'folder-caso'])->_real();
        $tenant = $pasta->getTenant();

        // A > B > C, três níveis
        $anterior = null;
        foreach (['A', 'B', 'C'] as $nome) {
            $secao = new PastaSecao();
            $secao->setPasta($pasta);
            $secao->setTenant($tenant);
            $secao->setNome($nome);
            $secao->setOrdem(1);
            $secao->setPai($anterior);
            $em->persist($secao);
            $anterior = $secao;
        }

        // documento na folha (C), sem drive_file_id → candidato a subir
        $storage     = self::getContainer()->get(ArquivoStorageInterface::class);
        $uploadsDir  = (string) self::getContainer()->getParameter('uploads_dir');
        $nomeStorage = $storage->salvarConteudo('conteudo', $uploadsDir, 'pdf');

        $doc = (new PastaDocumento())
            ->setTitulo('PECA')
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo($nomeStorage)
            ->setNomeOriginal('peca.pdf')
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10)
            ->setPasta($pasta)
            ->setTenant($tenant);
        $doc->setSecao($anterior);
        $em->persist($doc);
        $em->flush();

        $docId  = $doc->getId();
        $client = new FakeGoogleDriveClient();
        $r      = new ResultadoReconciliacaoPasta();

        self::getContainer()->get(ReconciliadorDePasta::class)->reconciliarArquivosDaPasta(
            (int) $pasta->getId(),
            $client,
            false,
            $r,
            ModoSincronizacao::Enviar,
        );

        self::assertSame(0, $r->erros, 'a árvore nova não pode gerar erro no envio');
        self::assertSame(1, $r->arquivosEnviados);

        // A asserção que importa: a subpasta criada no Drive é filha DIRETA da pasta do caso.
        // É isso que "achatado" quer dizer — nenhum A/B intermediário foi criado.
        $criadas = array_values(array_filter(
            $client->pastas,
            static fn (array $p): bool => $p['nome'] === 'C',
        ));
        self::assertCount(1, $criadas, 'exatamente uma subpasta-espelho da seção folha');
        self::assertSame('folder-caso', $criadas[0]['parent'], 'filha direta do caso: continua achatado');
        self::assertCount(1, $client->pastas, 'nenhuma pasta intermediária (A, B) foi criada');

        $em->clear();
        self::assertNotNull($em->find(PastaDocumento::class, $docId)->getDriveFileId());
    }
}
```

> ⚠️ Se `PastaFactory` não aceitar `driveFolderId` no `createOne`, monte a `Pasta` à mão como o
> `ReconciliadorDePastaTest` já faz — **não** altere a factory, que é compartilhada.

- [ ] **Step 2: Rodar**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter ReconciliadorArvoreNaoRegridTest
```

Esperado: PASS sem tocar em `ReconciliadorDePasta.php`. **Se falhar, pare** — significa que a Entrega 1 regrediu o sync, e isso não estava previsto.

- [ ] **Step 3: Commit**

```bash
git add app/tests/Sync/Functional/ReconciliadorArvoreNaoRegridTest.php
git commit -m "prova que a arvore nova nao quebra o envio ao Drive"
```

---

### Task 10: Fechamento da frente

**Files:**
- Modify: `docs/frentes-ativas.md`

- [ ] **Step 1: Suíte completa da frente**

```bash
scripts/frente-testar.sh pasta-subpastas-aninhadas
```

Esperado: verde. Anote o número (`NNNN/NNNN`) — ele entra no relato.

- [ ] **Step 2: Verificações transversais**

```bash
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/pasta-subpastas-aninhadas/app && php bin/console lint:twig templates && php bin/console lint:container && php bin/console doctrine:schema:validate'
```

- [ ] **Step 3: Trazer o master para dentro da frente e rodar DE NOVO**

Uma branch cortada de `origin/master` prova `master + esta frente`. Não prova `master + esta frente + o que entrou no master enquanto isso`. Este passo é o que todo mundo pula.

```bash
scripts/frente-fechar.sh pasta-subpastas-aninhadas
```

⚠️ Se a suíte explodir com `column ... does not exist`, **não é código** — é o banco da frente sem alguma migration nova do master. Ver `docs/frentes-ativas.md`.

- [ ] **Step 4: Revisão**

Rodar `/review` com a spec como alvo:

```
/review docs/specs/pasta-subpastas-aninhadas.md
```

O `feature-review-agent` é read-only e só aponta — quem corrige é o orquestrador.

- [ ] **Step 5: Entregar a lista de smoke ao dono**

**Não abra o navegador.** Entregue esta lista:

1. Criar pasta na raiz → aparece na raiz
2. Entrar nela e criar outra dentro → a nova aparece **dentro**, não na raiz
3. Descer 3 níveis → o caminho no topo mostra os 3 e cada degrau volta para o nível certo
4. Arrastar **pela alcinha** → reordena, **não** move
5. Arrastar **pelo cartão** para cima de outra pasta → move para dentro
6. Menu ⋮ → "Mover para..." → escolher a raiz → a pasta volta para o topo
7. Tentar mover uma pasta para dentro da própria filha → recusa com mensagem
8. Apagar pasta com conteúdo → o aviso **conta** as subpastas e os arquivos
9. Buscar um arquivo que está 3 níveis abaixo → aparece, e mostra em que pasta está
10. Abrir a tela de **documentos de um caso de Cobrança** → continua funcionando com um nível só

- [ ] **Step 6: Tirar a frente do registro**

Remover a linha de `docs/frentes-ativas.md` **só depois** do merge (que é do humano).

---

## Notas para quem executa

**O que este plano NÃO faz** (spec §8 e §9): espelhar a árvore no Drive, e realinhar os 8.610 arquivos já achatados. Se você se pegar mexendo em `ReconciliadorDePasta.php` para além do teste de regressão da Task 9, **pare** — saiu do escopo.

**Git:** commits locais são permitidos. `push`, `merge`, `rebase` e `reset` são do humano — monte o comando, explique, e entregue em bloco `# Execute manualmente no terminal externo`.

**Suíte verde não diz nada sobre aparência.** Já aconteceu neste projeto de 3.459 testes passarem com o layout visivelmente quebrado. A Task 8 inteira depende do smoke da Task 10.

<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Ponto\Armazenamento\ChavesDePonto;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Validacao\RestricoesAnexoJustificativa;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Shared\Http\FonteDeUploadHttp;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Substitui o anexo de uma justificativa de ponto — atingindo TODO o lote de abono.
 *
 * ## Por que o lote inteiro
 *
 * Um lote (`batchId`) nasce como unidade: `PontoController::novaJustificativa` faz UM upload fora
 * do laço e grava a mesma string em N registros, um por dia. Em produção existe um lote de 27 dias
 * apoiado num único atestado. A edição antiga trocava o anexo de UM registro (o `{id}` da rota) —
 * e o modal sempre abre pelo primeiro do lote —, então trocar o atestado deixaria 1 dia com o
 * arquivo novo e 26 com o antigo. Medido em produção: isso ainda não aconteceu, nenhum `batchId`
 * tem dois `anexo_path`. O invariante que este UseCase preserva é **um batchId → um anexo**.
 *
 * ## Por que o arquivo antigo só some DEPOIS do commit
 *
 * O filesystem não participa da transação do PostgreSQL. A prioridade é explícita:
 *
 *   - aceitável: em falha excepcional, sobrar um arquivo órfão recuperável;
 *   - inaceitável: registro válido apontando para arquivo que não existe mais.
 *
 * Por isso a remoção física é a última coisa, depois do COMMIT.
 *
 * ## A janela entre COMMIT, contagem e DELETE
 *
 * Ela está fechada **por construção**, não por sincronização: existem exatamente três produtores de
 * `anexo_path` no repositório, e os três gravam o nome que o storage cunha para um `NovoArquivo`
 * (`NovoArquivo::cunharChave()`, desde a E2.4A), que é `bin2hex(random_bytes(16))` — nome novo a
 * cada chamada, nunca reaproveitado. Nenhum caminho
 * copia um `anexo_path` existente para outro registro. Logo, depois que a transação que removeu a
 * última referência comita, o conjunto de referências àquele arquivo só pode DIMINUIR: uma
 * contagem que dá zero é definitiva. `ProdutoresDeAnexoPathTest` é o que mantém essa premissa
 * verdadeira no futuro.
 *
 * Como defesa em profundidade, a fase 2 roda sob trava derivada do ARQUIVO (não do lote): o recurso
 * disputado é o arquivo, e travar pelo lote não bastaria se um dia um arquivo passasse a ser
 * referenciado por dois lotes.
 *
 * ## O arquivo NOVO, quando a fase 1 falha (E2.5)
 *
 * Ele é gravado dentro da transação, antes do COMMIT. Se a fase 1 falhar, ele só é apagado quando
 * estiver PROVADO que nada foi confirmado (`TransacaoComArquivoNovo`): num COMMIT que chega ao
 * servidor e perde a resposta, o lote inteiro passa a apontar para ele, e apagá-lo seria
 * exatamente o registro válido apontando para o vazio. Antes da E2.5 o arquivo saía em qualquer
 * falha.
 *
 * Todas as chaves saem de `ChavesDePonto` com o escopo da justificativa dona — nunca do `$tenant`
 * recebido por parâmetro (R1).
 */
final class SubstituirAnexoDoLoteUseCase
{
    /** Classes de trava (primeiro argumento de pg_advisory_xact_lock), separadas por recurso. */
    private const CLASSE_TRAVA_LOTE    = 4201;
    private const CLASSE_TRAVA_ARQUIVO = 4202;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JustificativaPontoRepository $repositorio,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly RemocaoAposTransacao $remocao,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \InvalidArgumentException quando o arquivo é recusado pela validação
     *
     * @return int quantos registros passaram a apontar para o anexo novo
     */
    public function executar(JustificativaPonto $justificativa, UploadedFile $arquivo, Tenant $tenant): int
    {
        // Posse do tenant é PRÉ-CONDIÇÃO, não detalhe. Sem ela, um descasamento entre o registro
        // e o escritório ativo faria `findLotePorBatchId` devolver vazio, a substituição atingir
        // um registro só e a fase 2 contar zero referências — apagando um arquivo que o lote
        // inteiro ainda usa. Ou seja: o caminho de erro viraria o caminho destrutivo. Hoje a porta
        // HTTP é fechada pelo TenantFilter, mas esta classe decide `unlink` e não pode depender
        // só disso (`app/src/CLAUDE.md`: nunca agir por id sem validar posse).
        $donoDoRegistro = $justificativa->getTenant();

        if ($donoDoRegistro === null || $donoDoRegistro->getId() !== $tenant->getId()) {
            throw new \LogicException(
                'A justificativa não pertence ao escritório informado; substituição recusada.',
            );
        }

        $this->validar($arquivo);

        $anexoAntigo = null;
        /** @var ChaveDeArquivo|null $novaChave */
        $novaChave = null;

        // ---- Fase 1: tudo o que é banco, numa transação só -------------------------------
        // A transação é a de `TransacaoComArquivoNovo`: mesma semântica do `wrapInTransaction`
        // (trabalho, flush, commit, EM fechado na falha, exceção original relançada), e ela decide
        // o destino do arquivo novo — inclusive quando quem falha é o próprio COMMIT, que um catch
        // dentro do closure nunca veria.
        $atingidos = $this->transacao->executar(
            function () use ($justificativa, $arquivo, $tenant, &$anexoAntigo, &$novaChave): int {
                $this->travar(self::CLASSE_TRAVA_LOTE, $this->chaveDoLote($justificativa, $tenant));

                // Reler DEPOIS de travar. Ler antes e gravar depois é como a corrida volta:
                // duas edições simultâneas do mesmo lote poderiam ressuscitar um valor velho.
                $lote = $this->loteDe($justificativa, $tenant);

                // O anexo a remover sai do BANCO, sob a trava, por projeção escalar — não
                // pelo getter. `findLotePorBatchId()` não relê os campos de uma entidade que
                // já esteja no identity map (sem HINT_REFRESH o UnitOfWork devolve a
                // instância gerenciada como está em memória), e a justificativa chega aqui
                // carregada pelo EntityValueResolver, muito antes da trava. Pelo getter,
                // a fase 2 decidiria sobre um valor que outra transação já pode ter trocado,
                // e o arquivo realmente substituído nunca seria contado nem removido.
                $anexoAntigo = $this->repositorio->anexoNoBancoPorId(
                    (int) $lote[0]->getId(),
                    $tenant,
                );

                // O escopo sai da justificativa dona, já conferida contra `$tenant` na
                // pré-condição (R1). Continua dentro da transação, como antes: a ordem é a da E1.
                $upload    = FonteDeUploadHttp::de($arquivo);
                $novaChave = $upload->gravarEm(
                    $this->armazenamento,
                    ChavesDePonto::novoAnexoDeJustificativa($justificativa, $upload->extensao),
                )->chave;

                foreach ($lote as $registro) {
                    $registro->setAnexoPath($novaChave->nome);
                }

                $this->em->flush();

                return \count($lote);
            },
            // Por referência: o arquivo só existe se o trabalho chegou a gravá-lo.
            function () use (&$novaChave): array {
                return $novaChave instanceof ChaveDeArquivo ? [$novaChave] : [];
            },
            'SubstituirAnexoDoLoteUseCase: anexo novo do lote',
        );

        $novoAnexo = $novaChave instanceof ChaveDeArquivo ? $novaChave->nome : null;

        // ---- Fase 2: só agora o disco, e só se ninguém mais referenciar -------------------
        if ($anexoAntigo !== null && $anexoAntigo !== '' && $anexoAntigo !== $novoAnexo) {
            $this->apagarAntigoSeOrfao($anexoAntigo, $justificativa, $tenant);
        }

        return $atingidos;
    }

    private function validar(UploadedFile $arquivo): void
    {
        $violacoes = $this->validator->validate($arquivo, RestricoesAnexoJustificativa::constraint());

        if (\count($violacoes) > 0) {
            throw new \InvalidArgumentException((string) $violacoes->get(0)->getMessage());
        }
    }

    /**
     * Registros que a substituição atinge: o lote inteiro quando há `batchId`; só o próprio
     * registro quando não há (justificativa avulsa, ou dado anterior ao campo).
     *
     * @return JustificativaPonto[]
     */
    private function loteDe(JustificativaPonto $justificativa, Tenant $tenant): array
    {
        $batchId = $justificativa->getBatchId();

        if ($batchId === null || $batchId === '') {
            return [$justificativa];
        }

        $lote = $this->repositorio->findLotePorBatchId($batchId, $tenant);

        // Vazio aqui é IMPOSSÍVEL no caminho correto: a pré-condição de posse já garantiu que o
        // próprio registro pertence a este tenant, logo ele volta na consulta. Se voltar vazio,
        // alguma premissa caiu — e seguir adiante com `[$justificativa]` seria exatamente o
        // comportamento perigoso: atingir um registro só e depois contar zero referências.
        if ($lote === []) {
            throw new \LogicException(sprintf(
                'Lote %s não encontrado para o escritório informado; substituição recusada.',
                $batchId,
            ));
        }

        return $lote;
    }

    /**
     * `$dona` é a justificativa persistida que apontava para o anexo antigo: é dela que sai o
     * escopo da chave. O nome vem de fora porque foi lido por projeção escalar sob a trava — o
     * getter pode estar velho (ver fase 1).
     */
    private function apagarAntigoSeOrfao(string $anexoAntigo, JustificativaPonto $dona, Tenant $tenant): void
    {
        try {
            $this->em->wrapInTransaction(function () use ($anexoAntigo, $dona, $tenant): void {
                $this->travar(self::CLASSE_TRAVA_ARQUIVO, $this->chaveDoArquivo($anexoAntigo));

                if ($this->repositorio->contarReferenciasAoAnexo($anexoAntigo, $tenant) > 0) {
                    return;
                }

                // Ainda sob a trava do arquivo (defesa em profundidade): a fase 1 já comitou, e esta
                // transação não grava nada. Falha física vira registro e órfão recuperável.
                $this->remocao->remover(
                    [ChavesDePonto::anexoDeJustificativaPorNome($dona, $anexoAntigo)],
                    'SubstituirAnexoDoLoteUseCase: anexo antigo sem referências',
                );
            });
        } catch (\Throwable $e) {
            // Falhar aqui deixa um arquivo órfão recuperável — o lado aceitável da troca. O que
            // não pode acontecer é propagar e dar a impressão de que a substituição falhou:
            // ela já está comitada.
            $this->logger->warning('Não foi possível remover o anexo antigo da justificativa.', [
                'anexo' => $anexoAntigo,
                'erro'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * `pg_advisory_xact_lock` só é liberada no fim da TRANSAÇÃO — sem uma aberta, a trava cairia
     * no mesmo instante e viraria enfeite. Mesma lição já registrada em `NumeracaoDePasta::travar`.
     */
    private function travar(int $classe, int $chave): void
    {
        $conn = $this->em->getConnection();

        if (!$conn->isTransactionActive()) {
            throw new \LogicException(
                'A substituição de anexo exige uma transação aberta: a trava só segura até o commit.',
            );
        }

        $conn->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [$classe, $chave]);
    }

    private function chaveDoLote(JustificativaPonto $justificativa, Tenant $tenant): int
    {
        return $this->paraInt32(($tenant->getId() ?? 0) . ':' . ($justificativa->getBatchId() ?? ('id:' . $justificativa->getId())));
    }

    /**
     * A chave NÃO leva o tenant: o recurso disputado é o arquivo, e o diretório
     * `justificativas_uploads_dir` é plano e compartilhado por todos os escritórios. Misturar o
     * tenant daria chaves diferentes para o mesmo arquivo físico, e as duas transações não
     * serializariam — que é o oposto do que esta trava existe para fazer.
     */
    private function chaveDoArquivo(string $anexo): int
    {
        return $this->paraInt32('anexo:' . $anexo);
    }

    /**
     * O segundo argumento de `pg_advisory_xact_lock` é int4. `crc32()` devolve 0..2^32-1 em PHP de
     * 64 bits, então a metade alta precisa virar negativa — calculado aqui, e não com o `hashtext`
     * do PostgreSQL, que não é função documentada.
     */
    private function paraInt32(string $chave): int
    {
        $valor = crc32($chave);

        return $valor > 2147483647 ? $valor - 4294967296 : $valor;
    }
}

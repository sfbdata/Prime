<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Validacao\RestricoesAnexoJustificativa;
use App\Shared\Service\ArquivoStorageInterface;
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
 * `anexo_path` no repositório, e os três gravam o retorno de `ArquivoStorageService::salvar()`,
 * que é `bin2hex(random_bytes(16))` — nome novo a cada chamada, nunca reaproveitado. Nenhum caminho
 * copia um `anexo_path` existente para outro registro. Logo, depois que a transação que removeu a
 * última referência comita, o conjunto de referências àquele arquivo só pode DIMINUIR: uma
 * contagem que dá zero é definitiva. `ProdutoresDeAnexoPathTest` é o que mantém essa premissa
 * verdadeira no futuro.
 *
 * Como defesa em profundidade, a fase 2 roda sob trava derivada do ARQUIVO (não do lote): o recurso
 * disputado é o arquivo, e travar pelo lote não bastaria se um dia um arquivo passasse a ser
 * referenciado por dois lotes.
 */
final class SubstituirAnexoDoLoteUseCase
{
    /** Classes de trava (primeiro argumento de pg_advisory_xact_lock), separadas por recurso. */
    private const CLASSE_TRAVA_LOTE    = 4201;
    private const CLASSE_TRAVA_ARQUIVO = 4202;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JustificativaPontoRepository $repositorio,
        private readonly ArquivoStorageInterface $storage,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
        private readonly string $justificativasUploadsDir,
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

        // ---- Fase 1: tudo o que é banco, numa transação só -------------------------------
        try {
            $atingidos = $this->em->wrapInTransaction(
                function () use ($justificativa, $arquivo, $tenant, &$anexoAntigo, &$novoAnexo): int {
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

                    $novoAnexo = $this->storage->salvar($arquivo, $this->justificativasUploadsDir);

                    foreach ($lote as $registro) {
                        $registro->setAnexoPath($novoAnexo);
                    }

                    $this->em->flush();

                    return \count($lote);
                },
            );
        } catch (\Throwable $e) {
            // O `try` envolve a CHAMADA, não o closure: `wrapInTransaction` executa o closure,
            // depois faz flush e commit por fora dele. Um catch interno não veria falha de
            // COMMIT — que é justamente a falha mais provável da fase 1 (queda de conexão,
            // statement_timeout). O arquivo novo foi gravado antes do commit; se o banco
            // recusou, ninguém chegou a referenciá-lo.
            if (isset($novoAnexo) && \is_string($novoAnexo)) {
                $this->removerBestEffort($novoAnexo, 'rollback da substituição');
            }

            throw $e;
        }

        // ---- Fase 2: só agora o disco, e só se ninguém mais referenciar -------------------
        if ($anexoAntigo !== null && $anexoAntigo !== '' && $anexoAntigo !== $novoAnexo) {
            $this->apagarAntigoSeOrfao($anexoAntigo, $tenant);
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

    private function apagarAntigoSeOrfao(string $anexoAntigo, Tenant $tenant): void
    {
        try {
            $this->em->wrapInTransaction(function () use ($anexoAntigo, $tenant): void {
                $this->travar(self::CLASSE_TRAVA_ARQUIVO, $this->chaveDoArquivo($anexoAntigo));

                if ($this->repositorio->contarReferenciasAoAnexo($anexoAntigo, $tenant) > 0) {
                    return;
                }

                $caminho = $this->storage->caminho($this->justificativasUploadsDir, $anexoAntigo);

                if ($this->storage->existe($caminho)) {
                    $this->storage->excluir($caminho);
                }
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

    private function removerBestEffort(string $nomeArquivo, string $motivo): void
    {
        try {
            $caminho = $this->storage->caminho($this->justificativasUploadsDir, $nomeArquivo);

            if ($this->storage->existe($caminho)) {
                $this->storage->excluir($caminho);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Não foi possível remover o anexo recém-gravado.', [
                'anexo'  => $nomeArquivo,
                'motivo' => $motivo,
                'erro'   => $e->getMessage(),
            ]);
        }
    }
}

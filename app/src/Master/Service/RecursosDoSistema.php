<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Entity\Auth\User;
use App\Master\DTO\EstadoDoRecursoOutput;
use App\Master\Repository\RecursoEstadoRepository;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Leitura do estado das telas e funções (spec docs/specs/perfis-1.2-telas-e-recursos.md, R2 a R6).
 *
 * Uma consulta por requisição: o menu pergunta várias chaves por página, e o guarda de rota mais
 * uma. O cache é por requisição PRINCIPAL (WeakMap), e não por instância: o serviço sobrevive entre
 * requisições no mesmo kernel (testes com disableReboot, worker), e um cache de instância mostraria
 * o estado de antes da mudança.
 *
 *  - `estaAtivo`: estado efetivo (R4: data vencida = ativo). Chave fora do catálogo = ativo.
 *  - `visivel`: false só para quem NÃO é Master com o item desligado (R5).
 *  - `marca`: a classe da marcação vermelha, só para o Master com o item desligado (R6).
 *
 * Sem a tabela `recurso_estado` (código publicado antes da migration rodar), tudo vale como ativo,
 * com aviso no log: esta leitura roda em toda página logada e não pode derrubar o sistema.
 */
final class RecursosDoSistema
{
    public const CLASSE_MARCA = 'bj-recurso-off';

    /** @var \WeakMap<Request, array<string, EstadoDoRecursoOutput>> */
    private \WeakMap $porRequisicao;

    /** @var array<string, EstadoDoRecursoOutput>|null fora de requisição (console). */
    private ?array $semRequisicao = null;

    public function __construct(
        private readonly RecursoEstadoRepository $repositorio,
        private readonly ClockInterface $relogio,
        private readonly Security $security,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
        $this->porRequisicao = new \WeakMap();
    }

    public function estado(string $chave): EstadoDoRecursoOutput
    {
        return $this->estados()[$chave] ?? EstadoDoRecursoOutput::ativo($chave);
    }

    public function estaAtivo(string $chave): bool
    {
        return $this->estado($chave)->ativo;
    }

    public function visivel(string $chave): bool
    {
        return $this->estaAtivo($chave) || $this->usuarioEhMaster();
    }

    public function marca(string $chave): string
    {
        return !$this->estaAtivo($chave) && $this->usuarioEhMaster() ? self::CLASSE_MARCA : '';
    }

    public function usuarioEhMaster(): bool
    {
        $usuario = $this->security->getUser();

        return $usuario instanceof User && $this->regraDosMasters->ehMaster($usuario);
    }

    /**
     * As 19 linhas do catálogo com o estado efetivo de cada uma, para a aba do Master.
     *
     * @return list<array{g: string, chave: string, t: string, ic: string, d: ?string, tela: bool, base: bool, ativo: bool, motivo: ?string, ate: ?string}>
     */
    public function paraOPainel(): array
    {
        return array_map(function (array $linha): array {
            $estado = $this->estado($linha['chave']);

            return $linha + ['ativo' => $estado->ativo, 'motivo' => $estado->motivo, 'ate' => $estado->ate];
        }, CatalogoDeRecursos::LINHAS);
    }

    /** @return array<string, EstadoDoRecursoOutput> */
    private function estados(): array
    {
        $requisicao = $this->requestStack->getMainRequest();
        if ($requisicao === null) {
            return $this->semRequisicao ??= $this->carregar();
        }

        return $this->porRequisicao[$requisicao] ??= $this->carregar();
    }

    /** @return array<string, EstadoDoRecursoOutput> */
    private function carregar(): array
    {
        $hoje = $this->relogio->now();

        try {
            $gravados = $this->repositorio->todosPorChave();
        } catch (TableNotFoundException $e) {
            $this->logger->warning('Telas e recursos: tabela recurso_estado inexistente (migration pendente); tudo vale como ativo.', ['exception' => $e]);

            return [];
        }

        return array_map(
            static fn ($estado): EstadoDoRecursoOutput => EstadoDoRecursoOutput::fromEntity($estado, $hoje),
            $gravados,
        );
    }
}

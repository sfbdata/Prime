<?php

declare(strict_types=1);

namespace App\Master\Notificacoes;

use App\Contribua\Entity\Sugestao;
use App\Contribua\Enum\TipoDeSugestao;
use App\Contribua\Repository\SugestaoRepository;
use App\Contribua\Service\VisaoDoMaster;
use App\Entity\Auth\User;
use App\Master\DTO\DestinoDaResposta;
use App\Master\DTO\MensagemDaCentral;
use App\Master\DTO\RespostaNaOrigem;
use App\Repository\UserRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * Origem `sugestao`: as sugestões do Contribua de TODOS os escritórios. A tabela é TenantAware, então toda leitura
 * passa pela {@see VisaoDoMaster}, a porta do Contribua para o Master (D4), sem desligar o filtro à mão.
 *
 * A resposta vai ao e-mail do autor (User); sem e-mail válido, vira aviso na Central do autor, no escritório da
 * sugestão (D6). Autor que saiu do BlueJus (SET NULL) não tem a quem responder. Ao responder, a sugestão fica
 * atendida com a resposta, como na página do Contribua.
 */
final class LeitorDeSugestoes implements OrigemDaCentral
{
    public const ORIGEM = 'sugestao';

    public function __construct(
        private readonly SugestaoRepository $sugestoes,
        private readonly VisaoDoMaster $visaoDoMaster,
        private readonly UserRepository $usuarios,
    ) {
    }

    public function origem(): string
    {
        return self::ORIGEM;
    }

    public function todas(): array
    {
        $linhas = $this->visaoDoMaster->deTodosOsEscritorios(fn (): array => $this->consulta()
            ->orderBy('s.criadaEm', 'DESC')->addOrderBy('s.id', 'DESC')
            ->setMaxResults(self::LIMITE)
            ->getQuery()->getArrayResult());

        return array_map($this->emMensagem(...), $linhas);
    }

    public function uma(int $idOrigem): ?MensagemDaCentral
    {
        $linha = $this->linha($idOrigem);

        return $linha === null ? null : $this->emMensagem($linha);
    }

    public function destinoDaResposta(int $idOrigem): ?DestinoDaResposta
    {
        $linha = $this->linha($idOrigem);
        $sugestao = $this->sugestao($idOrigem);
        if ($linha === null || $linha['autorId'] === null || $sugestao === null) {
            return null;
        }
        $autor = $this->usuarios->find((int) $linha['autorId']);

        return $autor instanceof User ? new DestinoDaResposta($autor->getEmail(), $autor, $sugestao->getTenant()) : null;
    }

    /** A resposta que a página do Contribua gravou na sugestão (texto, quem e quando), se houver. */
    public function respostaNaOrigem(int $idOrigem): ?RespostaNaOrigem
    {
        $linha = $this->linha($idOrigem);
        $texto = is_string($linha['resposta'] ?? null) ? trim($linha['resposta']) : '';
        if ($linha === null || $texto === '') {
            return null;
        }
        $por = $linha['respondidaPorId'] === null ? null : $this->usuarios->find((int) $linha['respondidaPorId']);
        $em = $linha['respondidaEm'] instanceof \DateTimeImmutable ? $linha['respondidaEm'] : $linha['criadaEm'];

        return new RespostaNaOrigem($texto, $por instanceof User ? $por : null, $em instanceof \DateTimeImmutable ? $em : new \DateTimeImmutable());
    }

    /**
     * Grava a resposta na própria sugestão pela API dela (`alternarAtendimento`), como o desenho (`g.resp`): já
     * atendida, reabre e atende de novo, para a resposta nova valer com o Master e a data de agora.
     */
    public function registrarResposta(int $idOrigem, string $texto, User $master): void
    {
        $sugestao = $this->sugestao($idOrigem);
        if ($sugestao === null) {
            return;
        }
        if ($sugestao->isAtendida()) {
            $sugestao->alternarAtendimento(null, $master);
        }
        $sugestao->alternarAtendimento($texto, $master);
        $this->sugestoes->salvar($sugestao);
    }

    private function consulta(): QueryBuilder
    {
        return $this->sugestoes->createQueryBuilder('s')
            ->select('s.id AS id', 's.tipo AS tipo', 's.autorNome AS autorNome', 's.texto AS texto', 's.criadaEm AS criadaEm', 's.resposta AS resposta')
            ->addSelect('IDENTITY(s.tenant) AS tenantId', 'a.id AS autorId', 'a.email AS autorEmail')
            ->addSelect('s.respondidaEm AS respondidaEm', 'IDENTITY(s.respondidaPor) AS respondidaPorId')
            ->leftJoin('s.autor', 'a');
    }

    /** @return array<string, mixed>|null */
    private function linha(int $idOrigem): ?array
    {
        $linhas = $this->visaoDoMaster->deTodosOsEscritorios(fn (): array => $this->consulta()
            ->andWhere('s.id = :id')->setParameter('id', $idOrigem)
            ->getQuery()->getArrayResult());

        return $linhas[0] ?? null;
    }

    private function sugestao(int $idOrigem): ?Sugestao
    {
        $sugestao = $this->visaoDoMaster->deTodosOsEscritorios(fn (): ?Sugestao => $this->sugestoes->find($idOrigem));

        return $sugestao instanceof Sugestao ? $sugestao : null;
    }

    /** @param array<string, mixed> $l */
    private function emMensagem(array $l): MensagemDaCentral
    {
        $tipo = $l['tipo'] instanceof TipoDeSugestao ? $l['tipo'] : (TipoDeSugestao::tryFrom((string) $l['tipo']) ?? TipoDeSugestao::Sugestao);
        $tenantId = $l['tenantId'] === null ? null : (int) $l['tenantId'];

        return new MensagemDaCentral(
            origem: self::ORIGEM,
            idOrigem: (int) $l['id'],
            tipo: $tipo->value,
            rotuloDoTipo: $tipo->rotulo(),
            deNome: (string) $l['autorNome'] !== '' ? (string) $l['autorNome'] : 'Equipe',
            deEmail: is_string($l['autorEmail']) && $l['autorEmail'] !== '' ? $l['autorEmail'] : null,
            escritorio: $tenantId === null ? '' : 'Conta Nº ' . str_pad((string) $tenantId, 6, '0', \STR_PAD_LEFT),
            tenantId: $tenantId,
            assunto: $tipo->rotulo(),
            texto: (string) $l['texto'],
            em: $l['criadaEm'] instanceof \DateTimeImmutable ? $l['criadaEm'] : new \DateTimeImmutable((string) $l['criadaEm']),
            podeResponder: $l['autorId'] !== null,
            respondidaNaOrigem: is_string($l['resposta'] ?? null) && trim($l['resposta']) !== '',
        );
    }
}

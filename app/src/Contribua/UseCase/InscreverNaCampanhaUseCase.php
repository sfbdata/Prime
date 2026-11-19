<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\Campanha;
use App\Contribua\Entity\CampanhaParticipacao;
use App\Contribua\Enum\EstadoDaCampanha;
use App\Contribua\Repository\CampanhaParticipacaoRepository;
use App\Contribua\Service\CampanhaVigente;
use App\Contribua\Service\CpfDaCampanha;
use App\Contribua\Service\FormatoDaCampanha;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Inscreve a pessoa no Desafio: nasce "aguardando pagamento" (o Master confere o Pix e confirma). Uma inscrição por
 * pessoa, por CPF e por OAB. Dentro de uma transação com trava por campanha (`pg_advisory_xact_lock`), para duas
 * inscrições ao mesmo tempo não passarem do limite de vagas nem duplicarem CPF ou OAB.
 */
final class InscreverNaCampanhaUseCase
{
    /** Primeiro argumento do `pg_advisory_xact_lock` da inscrição (o segundo é o id da campanha). */
    public const CHAVE_DA_TRAVA = 4304;

    public function __construct(
        private readonly CampanhaVigente $vigente,
        private readonly CampanhaParticipacaoRepository $participacoes,
        private readonly CpfDaCampanha $cpfs,
        private readonly EntityManagerInterface $em,
        private readonly Connection $conexao,
    ) {
    }

    /** @return string a mensagem de sucesso */
    public function executar(Solicitante $quem, string $nome, string $cpf, string $oab, bool $aceite, ?string $ip): string
    {
        $campanha = $this->vigente->campanha();
        if ($campanha === null) {
            throw new \DomainException('A campanha não está aberta para inscrições.');
        }
        $this->exigirAberta($this->vigente->estado($campanha));
        if ($this->participacoes->daPessoa($campanha, $quem->usuario) !== null) {
            throw new \DomainException('Você já está no Desafio.');
        }
        if (!$aceite) {
            throw new \DomainException('Para entrar, marque que leu e aceita as regras.');
        }
        if (!$this->cpfs->valido($cpf)) {
            throw new \DomainException('Informe um CPF válido.');
        }
        $oab = (string) preg_replace('/\s+/', '', mb_strtoupper(trim($oab)));
        if ($oab === '') {
            throw new \DomainException('Informe sua inscrição na OAB.');
        }
        if (mb_strlen($oab) > 20) {
            throw new \DomainException('A inscrição na OAB pode ter até 20 caracteres.');
        }
        $nome = mb_substr(trim($nome) !== '' ? trim($nome) : $quem->nome(), 0, 120);
        $hash = $this->cpfs->hash($cpf);
        $agora = $this->vigente->agora();

        $this->em->wrapInTransaction(function () use ($campanha, $quem, $nome, $hash, $cpf, $oab, $agora, $ip): void {
            $this->conexao->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::CHAVE_DA_TRAVA, $campanha->getId()]);
            // Já dentro da trava: o que outra inscrição gravou um instante atrás já vale.
            $this->exigirAberta($this->vigente->estado($campanha));
            if ($this->participacoes->daPessoa($campanha, $quem->usuario) !== null) {
                throw new \DomainException('Você já está no Desafio.');
            }
            if ($this->participacoes->cpfOuOabJaInscritos($campanha, $hash, $oab)) {
                throw new \DomainException('Este CPF ou esta inscrição na OAB já está no Desafio.');
            }
            $participacao = new CampanhaParticipacao($campanha, $quem->usuario, $quem->escritorio, $nome, $hash, $this->cpfs->doisUltimos($cpf), $oab, $agora, $ip, $agora);
            $this->participacoes->salvar($participacao, flush: true);
            $participacao->gerarTxid();
            $this->em->flush();
        });

        return 'Inscrição registrada. Pague ' . FormatoDaCampanha::reais($campanha->getPrecoCentavos()) . ' com o Pix para entrar no Desafio.';
    }

    private function exigirAberta(EstadoDaCampanha $estado): void
    {
        if ($estado === EstadoDaCampanha::Esgotada) {
            throw new \DomainException('Vagas esgotadas.');
        }
        if ($estado !== EstadoDaCampanha::Ativa) {
            throw new \DomainException('A campanha não está aberta para inscrições.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\GuardaDeConvites;
use App\TrabalhoExterno\Service\TrabalhoExternoMailer;

/**
 * "Convidar profissional por e-mail" (aba Execução, spec §8).
 *
 * Quem: Master, ou Equipe com "Convidar e revogar profissionais". Situação: aceita ou em execução.
 * Fluxo: o convite ativo anterior é revogado (um executor por vez, como o desenho); o novo nasce
 * com as permissões marcadas e validade (padrão hoje + 15 dias); o convidado vira o responsável;
 * o chat recebe o registro; DEPOIS do COMMIT sai o e-mail com o link (o token só existe nele).
 * Alternativo: nome vazio, e-mail inválido ou validade no passado → 422.
 */
final class ConvidarProfissionalUseCase
{
    public function __construct(
        private readonly GuardaDeConvites $guarda,
        private readonly DemandaExternaRepository $repositorio,
        private readonly TrabalhoExternoMailer $mailer,
    ) {
    }

    /** @param array<string, mixed> $dados nome, email, funcao, validade (Y-m-d), permissoes {chave: bool} */
    public function executar(Contexto $ctx, string $codigo, array $dados): DemandaExterna
    {
        $nome = mb_substr(trim((string) ($dados['nome'] ?? '')), 0, 255);
        $email = mb_strtolower(trim((string) ($dados['email'] ?? '')));
        if ($nome === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 180) {
            throw new DadosInvalidos('Informe nome e um e-mail válido.');
        }
        $hoje = new \DateTimeImmutable('today');
        $validade = $hoje->modify('+15 days');
        if (trim((string) ($dados['validade'] ?? '')) !== '') {
            $validade = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $dados['validade']) ?: throw new DadosInvalidos('Validade inválida.');
            if ($validade < $hoje) {
                throw new DadosInvalidos('A validade do convite não pode estar no passado.');
            }
        }
        $funcao = mb_substr(trim((string) ($dados['funcao'] ?? '')), 0, 120);
        $permissoes = is_array($dados['permissoes'] ?? null) ? $dados['permissoes'] : array_fill_keys(array_keys(ConviteDemanda::PERMISSOES), true);

        [$demanda, $ctx] = $this->guarda->demanda($ctx, $codigo, novoConvite: true);
        $token = bin2hex(random_bytes(32));
        $convite = new ConviteDemanda($demanda, $nome, $email, $funcao !== '' ? $funcao : null, $validade, ConviteDemanda::hashDoToken($token), $permissoes, $ctx->autor());
        $demanda->convidar($convite, $ctx->autor());
        $this->repositorio->salvar($demanda, flush: true);

        try {
            $this->mailer->enviarConvite($convite, $token);
        } catch (\RuntimeException) {
            // O banco já está decidido (o e-mail sai depois do COMMIT): a pessoa precisa saber que o
            // link não chegou, e o caminho para corrigir é reenviar (que gera um link novo).
            throw new DadosInvalidos('O convite foi registrado, mas o e-mail não saiu. Use "Reenviar convite".');
        }

        return $demanda;
    }
}

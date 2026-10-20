<?php

declare(strict_types=1);

namespace App\Indicacao\UseCase;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Indicacao\DTO\IndicarPorEmailInput;
use App\Indicacao\Entity\Indicacao;
use App\Indicacao\Exception\IndicacaoRecusada;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Indicacao\Service\CodigoDeIndicacao;
use App\Indicacao\Service\RegistroDeIndicacao;
use Psr\Clock\ClockInterface;
use Twig\Environment;

/**
 * "Enviar convite por e-mail" (spec `indicar-bluejus.md`, comportamento 4; bj-contas.js L130).
 *
 * Quem: qualquer logado, com ou sem escritório. Na trava da pessoa: cadastro completo e sem trava (ver
 * {@see RegistroDeIndicacao}); e-mail com formato válido; não é de quem já está no MEU escritório (só ele é consultado:
 * olhar contas de fora deixaria descobrir quem tem conta). Grava a indicação e manda pelo Carteiro, lista `indicacao`,
 * com o modelo FIXO (o texto livre da caixa vai só no WhatsApp: e-mail da plataforma com texto livre vira canal de
 * spam). Descadastrado (`bloqueado_descadastro`): a indicação fica gravada e a resposta é o mesmo sucesso, para não
 * revelar quem saiu da lista. Tudo numa transação: falha no envio desfaz a indicação.
 */
final class IndicarPorEmailUseCase
{
    public const LISTA = 'indicacao';
    public const ORIGEM = 'indicacao';
    public const TEMPLATE = 'email/indicacao/convite.html.twig';

    public const EMAIL_INVALIDO = 'Informe um e-mail válido.';
    public const JA_FAZ_PARTE = 'Esta pessoa já faz parte da sua conta.';

    public function __construct(
        private readonly RegistroDeIndicacao $registro,
        private readonly IndicacaoRepository $indicacoes,
        private readonly CodigoDeIndicacao $codigos,
        private readonly CarteiroInterface $carteiro,
        private readonly Environment $twig,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** Devolve a mensagem de sucesso; recusa com {@see IndicacaoRecusada} e o texto do desenho. */
    public function executar(IndicarPorEmailInput $input, User $user, ?Tenant $escritorio): string
    {
        $email = MensagemDeEmail::normalizar($input->email);

        return $this->registro->comTrava($user, function () use ($email, $input, $user, $escritorio): string {
            $this->exigirEmailValido($email);
            $this->exigirQueNaoEhDaMinhaConta($email, $escritorio);
            $indicacao = Indicacao::porEmail($user, $escritorio, $email, $input->nome, $this->relogio->now());
            $this->registro->gravar($indicacao, $user, $escritorio);
            $this->carteiro->enviar($this->convite($indicacao, $user));

            return sprintf('Convite enviado para %s. Obrigado por indicar o BlueJus!', $email);
        });
    }

    private function exigirEmailValido(string $email): void
    {
        if ($email === '' || mb_strlen($email) > 180 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new IndicacaoRecusada(self::EMAIL_INVALIDO);
        }
    }

    private function exigirQueNaoEhDaMinhaConta(string $email, ?Tenant $escritorio): void
    {
        if ($escritorio !== null && $this->indicacoes->emailEhMembroDoEscritorio($email, (int) $escritorio->getId())) {
            throw new IndicacaoRecusada(self::JA_FAZ_PARTE);
        }
    }

    private function convite(Indicacao $indicacao, User $user): MensagemDeEmail
    {
        $quem = RegistroDeIndicacao::nomeDe($user);
        $corpo = $this->twig->render(self::TEMPLATE, [
            'quemIndica' => $quem,
            'nomeDoConvidado' => $indicacao->getNome(),
            'link' => $this->codigos->linkCurtoDe($user),
        ]);

        return new MensagemDeEmail(
            para: (string) $indicacao->getEmail(),
            assunto: sprintf('%s convidou você para o BlueJus', $quem),
            corpoHtml: $corpo,
            tipo: TipoDeEnvio::Lista,
            origem: self::ORIGEM,
            lista: self::LISTA,
            escopo: null,
            nome: $indicacao->getNome(),
            nomeDoRemetente: sprintf('%s via BlueJus', $quem),
            motivoDoEnvio: sprintf('Você recebeu este e-mail porque %s indicou o BlueJus para você.', $quem),
        );
    }
}

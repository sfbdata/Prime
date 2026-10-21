<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\DiaUtilDoEscritorio;
use App\Profile\DTO\ItensDoResumoDiario;
use App\Profile\DTO\ResultadoDoResumoDiario;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;
use App\Profile\Repository\ResumoDiarioEnvioRepository;
use App\Profile\Service\ColetorDoResumoDiario;
use App\Repository\UserTenantRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * "Resumo diário por e-mail · Prazos e metas do dia, às 8h" (desenho 07, prefT 'resumo'; spec
 * docs/specs/resumo-diario-por-email.md). Quem dispara: o cron das 8h de Brasília, um escritório por vez.
 *
 * Decisões do coordenador (21/10/2026): só em dia útil do escritório (feriado não envia); só para quem tem vínculo
 * ativo e conta ativa e deixou a chave ligada (padrão: ligada); dia sem nada não envia; o cadeado vale (pasta oculta
 * para a pessoa não aparece); uma vez por pessoa e dia (`resumo_diario_envio` grava antes do e-mail). Vai pelo
 * Carteiro, na lista `resumo_diario` (o descadastro do rodapé também vale), fora do teto diário das campanhas.
 */
final class EnviarResumoDiarioDoEscritorioUseCase
{
    public const ORIGEM   = 'resumo_diario';
    public const LISTA    = 'resumo_diario';
    public const TEMPLATE = 'email/perfil/resumo_diario.html.twig';
    public const MOTIVO   = 'Você recebe este resumo porque deixou ligado o Resumo diário por e-mail no Meu Perfil. Para parar, desligue lá.';

    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly PreferenciaDoUsuarioRepository $preferencias,
        private readonly ColetorDoResumoDiario $coletor,
        private readonly ResumoDiarioEnvioRepository $envios,
        private readonly DiaUtilDoEscritorio $diaUtil,
        private readonly CarteiroInterface $carteiro,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(Tenant $tenant, bool $simular = false): ResultadoDoResumoDiario
    {
        $agora = $this->relogio->now();
        if (!$this->diaUtil->ehDiaUtil($tenant, $agora)) {
            return ResultadoDoResumoDiario::semEnvio('Hoje não é dia útil no escritório.');
        }
        $desligaram = $this->preferencias->idsQueDesligaram($tenant, CatalogoDePreferenciasDoPerfil::RESUMO_EMAIL);
        $contagem = ['enviados' => 0, 'semNada' => 0, 'desligados' => 0, 'jaEnviados' => 0];
        foreach ($this->vinculos->findAtivosComPerfilPorTenant($tenant) as $vinculo) {
            $pessoa = $vinculo->getUser();
            if ($pessoa->isActive()) {
                ++$contagem[$this->paraPessoa($tenant, $pessoa, $agora, $desligaram, $simular)];
            }
        }

        return new ResultadoDoResumoDiario(...$contagem);
    }

    /**
     * @param list<int> $desligaram
     *
     * @return 'enviados'|'semNada'|'desligados'|'jaEnviados'
     */
    private function paraPessoa(Tenant $tenant, User $pessoa, \DateTimeImmutable $agora, array $desligaram, bool $simular): string
    {
        if (in_array((int) $pessoa->getId(), $desligaram, true)) {
            return 'desligados';
        }
        $dia = $agora->setTime(0, 0);
        $itens = $this->coletor->para($tenant, $pessoa, $dia);
        if ($itens->vazio()) {
            return 'semNada';
        }
        if ($simular) {
            return $this->envios->jaEnviado((int) $tenant->getId(), (int) $pessoa->getId(), $dia) ? 'jaEnviados' : 'enviados';
        }
        if (!$this->envios->registrarSeNovo((int) $tenant->getId(), (int) $pessoa->getId(), $dia, $agora)) {
            return 'jaEnviados';
        }
        $this->carteiro->enviar($this->mensagem($tenant, $pessoa, $dia, $itens));

        return 'enviados';
    }

    private function mensagem(Tenant $tenant, User $pessoa, \DateTimeImmutable $dia, ItensDoResumoDiario $itens): MensagemDeEmail
    {
        $corpo = $this->twig->render(self::TEMPLATE, [
            'nome'       => self::primeiroNome((string) $pessoa->getFullName()),
            'escritorio' => (string) $tenant->getName(),
            'dia'        => $dia,
            'itens'      => $itens,
            'url'        => $this->urls->generate('expediente_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        return new MensagemDeEmail(
            para: (string) $pessoa->getEmail(),
            assunto: 'Seus prazos e metas de hoje, ' . $dia->format('d/m'),
            corpoHtml: $corpo,
            tipo: TipoDeEnvio::Lista,
            origem: self::ORIGEM,
            lista: self::LISTA,
            escopo: $tenant,
            nome: $pessoa->getFullName(),
            titulo: 'Resumo do dia',
            nomeDoRemetente: 'BlueJus',
            motivoDoEnvio: self::MOTIVO,
        );
    }

    private static function primeiroNome(string $nome): string
    {
        $partes = preg_split('/\s+/', trim($nome)) ?: [];

        return (string) ($partes[0] ?? '');
    }
}

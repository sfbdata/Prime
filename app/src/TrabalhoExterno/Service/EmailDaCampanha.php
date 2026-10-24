<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\DTO\FiltroDaCampanha;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use App\TrabalhoExterno\Repository\VagaRepository;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * O e-mail da campanha do Master (`campDe` e `emailHTML`, desenho 06 l.940 a 957 e 1082 a 1086; spec VS
 * 18.10.26 §4.10): as vagas abertas nos filtros, os textos de cada público (assunto padrão, preheader,
 * título, texto e botão) e o HTML pelo Twig. O link das vagas é de quem chama: na prévia, a página
 * pública geral; na exportação, um link de vagas próprio ({@see linkDoToken()}).
 */
final class EmailDaCampanha
{
    public const NOME_DO_ARQUIVO = 'email-campanha-bluejus.html';
    private const TEMPLATE = 'trabalho_externo/campanha_email.html.twig';
    private const LOGO = 'images/logo/bluejus-white-transparent-nav.png';
    private const VAGAS_NO_EMAIL = 6;

    public function __construct(
        private readonly VagaRepository $vagas,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly Packages $assets,
    ) {
    }

    /**
     * As vagas abertas agora (o tempo da oferta e o da divulgação já contados) na UF e na área do
     * filtro. O modelo de Escritórios não lista vagas.
     *
     * @return list<Vaga>
     */
    public function vagasAbertas(FiltroDaCampanha $f, \DateTimeImmutable $agora): array
    {
        if ($f->ehEscritorios()) {
            return [];
        }

        return array_values(array_filter($this->vagas->abertas(), static fn (Vaga $v): bool => $v->situacaoEm($agora) === SituacaoVaga::Aberta
            && ($f->uf === '' || $v->getUf() === $f->uf)
            && ($f->area === '' || in_array($f->area, $v->getAreas(), true))));
    }

    /** O assunto que o modal mostra quando o Master não escreveu outro. */
    public function assuntoPadrao(FiltroDaCampanha $f, int $abertas): string
    {
        if ($f->ehEscritorios()) {
            return 'Divulgue sua vaga grátis e receba os melhores candidatos';
        }

        return $abertas > 0 ? '⚖️ ' . $abertas . ' vaga(s) jurídica(s) abertas para você' : '⚖️ Vagas para Advogados e área Jurídica no BlueJus';
    }

    /** O link da prévia: a página pública geral (para escritórios, na versão deles). */
    public function linkPublico(FiltroDaCampanha $f): string
    {
        return $this->urls->generate('trabalho_externo_vagas_publicas', $f->ehEscritorios() ? ['p' => FiltroDaCampanha::ESCRITORIOS] : [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** O link de vagas gerado para a exportação (o token só existe neste momento). */
    public function linkDoToken(string $token): string
    {
        return $this->urls->generate('trabalho_externo_vagas_link', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** @param list<Vaga> $abertas */
    public function html(FiltroDaCampanha $f, array $abertas, string $link): string
    {
        return $this->twig->render(self::TEMPLATE, ['e' => $this->modelo($f, $abertas, $link)]);
    }

    /**
     * O objeto `o` do `campDe` (l.1085).
     *
     * @param list<Vaga> $abertas
     *
     * @return array<string, mixed>
     */
    private function modelo(FiltroDaCampanha $f, array $abertas, string $link): array
    {
        $esc = $f->ehEscritorios();

        return [
            'pub' => $f->pub,
            'assunto' => $f->assunto ?? $this->assuntoPadrao($f, count($abertas)),
            'preheader' => $esc ? 'Sem anúncio pago. A equipe BlueJus revisa e envia os candidatos mais compatíveis.' : 'Crie sua conta grátis e candidate-se em 1 clique.',
            'titulo' => $esc ? 'Encontre advogados e profissionais jurídicos sem gastar com anúncio' : ($abertas !== [] ? 'Vagas para Advogados e área Jurídica' : 'Sua próxima vaga na área jurídica começa aqui'),
            'texto' => $esc
                ? 'Divulgue grátis para advogados, estagiários, correspondentes e equipe jurídica de todo o Brasil. A equipe BlueJus revisa a vaga e entrega a você uma seleção dos candidatos mais compatíveis.'
                : 'Escritórios da rede BlueJus estão contratando. Crie sua conta grátis para ver os detalhes e se candidatar em 1 clique.',
            'cta' => $esc ? 'Divulgar minha vaga grátis' : 'Criar conta grátis e ver as vagas',
            'link' => $link,
            'logo' => $this->logo(),
            'totalDeVagas' => count($abertas),
            'vagas' => array_map(self::vagaDoEmail(...), array_slice($abertas, 0, self::VAGAS_NO_EMAIL)),
        ];
    }

    /** @return array{titulo: string, cargoEContrato: string, local: string} */
    private static function vagaDoEmail(Vaga $v): array
    {
        $cidadeUf = implode('/', array_filter([$v->getCidade(), $v->getUf()]));

        return [
            'titulo' => $v->getTitulo(),
            'cargoEContrato' => implode(' · ', array_filter([$v->getCargo(), $v->getContratacao()])),
            'local' => implode(' · ', array_filter([$v->getModalidade(), $cidadeUf !== '' ? $cidadeUf : 'Brasil'])),
        ];
    }

    /** O logotipo branco com o endereço completo: o e-mail abre fora do BlueJus. */
    private function logo(): string
    {
        $caminho = $this->assets->getUrl(self::LOGO);
        $base = $this->urls->generate('trabalho_externo_vagas_publicas', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $origem = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $base);

        return str_starts_with($caminho, 'http') ? $caminho : $origem . '/' . ltrim($caminho, '/');
    }
}

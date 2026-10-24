<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\FiltroDaCampanha;
use App\TrabalhoExterno\Entity\LinkDeVagas;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\LinkDeVagasRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\DestinatariosDaCampanha;
use App\TrabalhoExterno\Service\EmailDaCampanha;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;
use App\TrabalhoExterno\Service\RegistroDaCampanha;

/**
 * "Baixar HTML do e-mail" e "Copiar HTML" do modal da campanha (desenho 06 l.1097; spec VS 18.10.26 §4.10):
 * o HTML que vai para a ferramenta de envio em massa. Só o Master, nunca na prévia.
 *
 * Diferente da prévia, o e-mail exportado leva um link de vagas próprio (o mesmo do "Divulgar vagas",
 * F2c) com as vagas abertas nos filtros: quem clicar cai na página só com elas, e as visitas contam no
 * painel. Sem vaga aberta, e no modelo de Escritórios, o link é a página pública geral (como no `campDe`,
 * l.1083). Cada exportação vai para o log, sem a lista.
 */
final class ExportarEmailDaCampanhaUseCase
{
    public function __construct(
        private readonly PoliticaDoCurriculo $politica,
        private readonly DestinatariosDaCampanha $destinatarios,
        private readonly EmailDaCampanha $email,
        private readonly LinkDeVagasRepository $links,
        private readonly RegistroDaCampanha $registro,
    ) {
    }

    /** @return array{html: string, destinatarios: int, vagas: int} */
    public function executar(Contexto $ctx, FiltroDaCampanha $filtro, string $acao): array
    {
        MontarCampanhaUseCase::exigirMaster($this->politica, $ctx);
        if (!in_array($acao, [RegistroDaCampanha::BAIXAR, RegistroDaCampanha::COPIAR], true)) {
            throw new DadosInvalidos('Ação desconhecida.');
        }
        $abertas = $this->email->vagasAbertas($filtro, new \DateTimeImmutable());
        $destinatarios = count($this->destinatarios->para($filtro)['lista']);

        $html = $this->email->html($filtro, $abertas, $this->link($ctx, $filtro, $abertas));
        $this->registro->registrar($ctx, $filtro, $acao, $destinatarios);

        return ['html' => $html, 'destinatarios' => $destinatarios, 'vagas' => count($abertas)];
    }

    /** @param list<Vaga> $abertas */
    private function link(Contexto $ctx, FiltroDaCampanha $filtro, array $abertas): string
    {
        if ($abertas === []) {
            return $this->email->linkPublico($filtro);
        }
        $nome = explode(' ', trim($ctx->autor()->nome))[0];
        [$link, $token] = LinkDeVagas::gerar(array_map(static fn (Vaga $v): int => (int) $v->getId(), $abertas), $ctx->usuario, $nome);
        $this->links->salvar($link, flush: true);

        return $this->email->linkDoToken($token);
    }
}

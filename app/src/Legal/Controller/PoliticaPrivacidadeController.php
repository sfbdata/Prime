<?php

declare(strict_types=1);

namespace App\Legal\Controller;

use App\Legal\PoliticaPrivacidadeVigente;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Leitura pública da Política de Privacidade.
 *
 * Sem `#[IsGranted]` de propósito: a Política precisa ser legível por quem ainda não tem
 * conta — o rodapé do login aponta para cá. A liberação de verdade é a entrada
 * `PUBLIC_ACCESS` no `security.yaml`; sem ela o coringa `^/` manda tudo para o login.
 *
 * As duas rotas renderizam **o mesmo partial**. Os Termos de Uso mantêm PDF e HTML como
 * duas cópias sincronizadas à mão (ver o aviso em {@see \App\Termo\TermoVigente}); aqui
 * o PDF sai do HTML na hora, então não há como divergirem.
 *
 * ⚠️ Limitação medida do PDF: o texto dele **não é pesquisável nem copiável**. O dompdf
 * embute a DejaVu Sans com `/Encoding /Identity-H` (os códigos no stream são índices de
 * glifo) e escreve um `/ToUnicode` identidade `<0000> <FFFF> <0000>`, que não traduz glifo
 * de volta para caractere — então Ctrl+F e copiar/colar devolvem lixo. Desligar
 * `isFontSubsettingEnabled` foi testado: infla o arquivo de 102 KB para 1,3 MB e **não**
 * corrige o ToUnicode. A versão canônica, pesquisável e acessível, é a página HTML; o PDF
 * serve para arquivar e imprimir. Não gaste tempo procurando erro de configuração aqui.
 */
final class PoliticaPrivacidadeController extends AbstractController
{
    public function __construct(
        private readonly PoliticaPrivacidadeVigente $vigente,
        // Interface, não a classe concreta: é o que permite o dublê de teste substituir
        // o serviço (ver RateLimiterFactoryEspiao). Com a classe concreta o container
        // recusa a troca e o controller nem chega a ser construído.
        private readonly RateLimiterFactoryInterface $politicaPrivacidadePdfLimiter,
    ) {}

    #[Route('/politica-de-privacidade', name: 'legal_politica_privacidade', methods: ['GET'])]
    public function ler(): Response
    {
        return $this->render('legal/politica_privacidade.html.twig', $this->contexto());
    }

    #[Route('/politica-de-privacidade.pdf', name: 'legal_politica_privacidade_pdf', methods: ['GET'])]
    public function pdf(Request $request): Response
    {
        if (!$this->politicaPrivacidadePdfLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Muitas requisições. Tente novamente em alguns instantes.');
        }

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        // Documento estático: nada aqui deve buscar recurso externo.
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->renderView('legal/politica_privacidade_pdf.html.twig', $this->contexto()));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response($dompdf->output(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            // O PDF não tem <head>, então o noindex da página não o alcança: para arquivo
            // binário o equivalente é este cabeçalho HTTP.
            'X-Robots-Tag' => 'noindex',
            'Content-Disposition' => sprintf(
                'inline; filename="politica-de-privacidade-%s.pdf"',
                PoliticaPrivacidadeVigente::VERSAO,
            ),
        ]);
    }

    /**
     * @return array{versao: string, data_publicacao: string}
     */
    private function contexto(): array
    {
        return [
            'versao' => PoliticaPrivacidadeVigente::VERSAO,
            // Formatado aqui, não no Twig: `null|date` imprime a data de hoje em silêncio,
            // e uma data errada num documento jurídico não tem sintoma visível.
            'data_publicacao' => $this->vigente->getDataPublicacao()->format('d/m/Y'),
        ];
    }
}

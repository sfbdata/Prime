<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\TeorDePublicacaoInput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mantém os prazos CALCULADOS das publicações do Push da pasta (docs/specs/prazo-do-push.md), irmão do
 * {@see PrazosDosRegistros}: `origem = push`, `registro_ref = push:<id da publicação>`.
 *
 * Lê as publicações do escritório cujo número CNJ é o de um processo da pasta (o mesmo casamento da aba Push e do
 * {@see DeterminacoesDoJuizo::daPasta()}), passa cada uma pela {@see CalculadoraDoPrazoDoPush} com o calendário da
 * UF do escritório e acerta a tabela: cria o que apareceu, apaga o que deixou de valer e não mexe no que continua
 * igual. A linha cumprida nunca é apagada. O prazo manual e o lido dos registros não são tocados. Quem chama dá o
 * flush.
 */
final class PrazosDoPush
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly CalculadoraDoPrazoDoPush $calculadora,
        private readonly DiasUteisDoEscritorio $diasUteis,
    ) {
    }

    /** @return array{criados: int, apagados: int} */
    public function sincronizar(Pasta $pasta): array
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null || $pasta->getId() === null) {
            return ['criados' => 0, 'apagados' => 0];
        }

        $desejados = $this->desejados($pasta, $tenant);
        $apagados = 0;
        foreach ($this->em->getRepository(PastaPrazo::class)->findBy(['pasta' => $pasta, 'tenant' => $tenant, 'origem' => PastaPrazo::ORIGEM_PUSH]) as $prazo) {
            $k = self::chave((string) $prazo->getRegistroRef(), $prazo->getTipo(), $prazo->getData()->format('Y-m-d'), (string) $prazo->getHora(), $prazo->getTitulo());
            if (isset($desejados[$k])) {
                unset($desejados[$k]);
                continue;
            }
            if (!$prazo->estaCumprido()) {
                $this->em->remove($prazo);
                ++$apagados;
            }
        }

        foreach ($desejados as [$publicacao, $p]) {
            $this->em->persist(PastaPrazo::doPush(
                $tenant,
                $pasta,
                (int) $publicacao->getId(),
                $publicacao->getDataDisponibilizacao() ?? new \DateTimeImmutable(),
                $p['tipo'],
                $p['titulo'],
                $p['data'],
                $p['hora'],
                $p['estimado']
            ));
        }

        return ['criados' => count($desejados), 'apagados' => $apagados];
    }

    /**
     * @return array<string, array{0: PublicacaoDjen, 1: array{tipo: string, titulo: string, data: \DateTimeImmutable, hora: ?string, estimado: bool}}>
     */
    private function desejados(Pasta $pasta, Tenant $tenant): array
    {
        $calendario = $this->diasUteis->daUf($tenant->getEstado());
        $desejados = [];
        foreach ($this->publicacoesDaPasta($pasta, $tenant) as $publicacao) {
            $ref = PastaPrazo::REF_PUSH . $publicacao->getId();
            foreach ($this->calculadora->daPublicacao(self::teor($publicacao), $calendario) as $p) {
                $desejados[self::chave($ref, $p['tipo'], $p['data']->format('Y-m-d'), (string) $p['hora'], $p['titulo'])] = [$publicacao, $p];
            }
        }

        return $desejados;
    }

    /**
     * Do escritório da pasta, só pelos números dos processos do MESMO escritório (processo de outro escritório
     * pendurado na pasta não abre a porta para as publicações dele).
     *
     * @return list<PublicacaoDjen>
     */
    private function publicacoesDaPasta(Pasta $pasta, Tenant $tenant): array
    {
        $numeros = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $processo = $vinculo->getProcesso();
            $digitos = preg_replace('/\D+/', '', (string) $processo->getNumeroProcesso()) ?? '';
            if ($digitos !== '' && $processo->getTenant()?->getId() === $tenant->getId()) {
                $numeros[$digitos] = true;
            }
        }
        if ($numeros === []) {
            return [];
        }

        return $this->publicacoes->findBy(['tenant' => $tenant, 'numeroProcesso' => array_map('strval', array_keys($numeros))], ['id' => 'ASC']);
    }

    private static function teor(PublicacaoDjen $publicacao): TeorDePublicacaoInput
    {
        return new TeorDePublicacaoInput(
            (int) $publicacao->getId(),
            $publicacao->getTipoDocumento(),
            $publicacao->getTipoComunicacao(),
            $publicacao->getDataDisponibilizacao(),
            $publicacao->getNumeroComunicacao(),
            (string) $publicacao->getTexto(),
        );
    }

    private static function chave(string $ref, string $tipo, string $data, string $hora, string $titulo): string
    {
        return implode('|', [$ref, $tipo, $data, $hora, mb_substr($titulo, 0, 255)]);
    }
}

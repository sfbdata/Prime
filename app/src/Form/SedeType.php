<?php

namespace App\Form;

use App\Entity\Tenant\Sede;
use App\Ponto\Validacao\RestricoesDaSede;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

class SedeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nome', TextType::class, [
                'label' => 'Nome da Sede',
            ])
            ->add('latitude', HiddenType::class, [
                'required' => true,
                // HiddenType sobe o erro para o form raiz, que a tela não mostra; fica no campo.
                'error_bubbling' => false,
                'constraints' => RestricoesDaSede::latitude(),
            ])
            ->add('longitude', HiddenType::class, [
                'required' => true,
                // HiddenType sobe o erro para o form raiz, que a tela não mostra; fica no campo.
                'error_bubbling' => false,
                'constraints' => RestricoesDaSede::longitude(),
            ])
            ->add('raioPermitido', NumberType::class, [
                'label' => 'Raio Permitido (metros)',
                'required' => true,
                // Sede nova nasce com 150 m (spec ponto-desenho-0811.md, P70 a); as de hoje não mudam.
                'empty_data' => (string) Sede::RAIO_PADRAO,
                'invalid_message' => RestricoesDaSede::MENSAGEM_RAIO_NAO_NUMERO,
                'attr' => ['min' => RestricoesDaSede::RAIO_MINIMO, 'max' => RestricoesDaSede::RAIO_MAXIMO, 'step' => 10],
                // Mesma regra da aba Configurações da /ponto (RestricoesDaSede).
                'constraints' => RestricoesDaSede::raio(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Sede::class,
        ]);
    }
}

<?php

/*
 * Copyright (C) 2009-2011 Internet Neutral Exchange Association Limited.
 * All Rights Reserved.
 *
 * This file is part of IXP Manager.
 *
 * IXP Manager is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, version v2.0 of the License.
 *
 * IXP Manager is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License v2.0
 * along with IXP Manager.  If not, see:
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */


/**
 * Controller: Peering Manager
 *
 * @author     Barry O'Donovan <barry@opensolutions.ie>
 * @category   INEX
 * @package    INEX_Controller
 * @copyright  Copyright (c) 2009 - 2012, Internet Neutral Exchange Association Ltd
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class PeeringManagerController extends INEX_Controller_AuthRequiredAction
{

    public function preDispatch()
    {
        // we should only be available to CUSTUSERs
        if( $this->getUser()->getPrivs() != \Entities\User::AUTH_CUSTUSER )
        {
            $this->addMessage( "You must be logged in as a standard user to access the peering manager.",
                OSS_Message::ERROR
            );
            $this->_redirect( '' );
        }
    }
    

    public function indexAction()
    {
        $this->view->vlans  = $vlans  = $this->getD2EM()->getRepository( '\\Entities\\Vlan' )->getPeeringVLANs();
        $this->view->protos = $protos = [ 4, 6 ];
        
        $bilat = array();
        foreach( $vlans as $vlan )
            foreach( $protos as $proto )
                $bilat[ $vlan->getNumber() ][$proto ] = $this->getD2EM()->getRepository( '\\Entities\\BGPSessionData' )->getPeers( $vlan->getId(), $proto );
        
        $this->view->bilat = $bilat;

        $peers = $this->getD2EM()->getRepository( '\\Entities\\Customer' )->getPeers( $this->getCustomer()->getId() );
        foreach( $peers as $i => $p )
        {
            // days since last peering request email sent
            if( !$p['email_last_sent'] )
                $peers[ $i ]['email_days'] = 0;
            else
                $peers[ $i ]['email_days'] = floor( ( time() - $p->getEmailLastSent()->getTimestamp() ) / 86400 );
        }
        $this->view->peers = $peers;

        $custs = $this->getD2EM()->getRepository( '\\Entities\\Customer' )->getForPeeringManager();

        $this->view->me = $me = $custs[ $this->getCustomer()->getAutsys() ];
        $this->view->myasn = $this->getCustomer()->getAutsys();
        unset( $custs[ $this->getCustomer()->getAutsys() ] );
        
        $potential       = [];
        $potential_bilat = [];
        $peered          = [];
        $rejected        = [];
        
        foreach( $custs as $c )
        {
            $custs[ $c['autsys' ] ]['ispotential'] = false;
            
            foreach( $vlans as $vlan )
            {
                if( isset( $me['vlaninterfaces'][ $vlan->getNumber() ] ) )
                {
                    if( isset( $c['vlaninterfaces'][$vlan->getNumber()] ) )
                    {
                        foreach( $protos as $proto )
                        {
                            if( $me['vlaninterfaces'][$vlan->getNumber()][0]["ipv{$proto}enabled"] && $c['vlaninterfaces'][$vlan->getNumber()][0]["ipv{$proto}enabled"] )
                            {
                                if( in_array( $c['autsys'], $bilat[$vlan->getNumber()][4][$me['autsys']]['peers'] ) )
                                    $custs[ $c['autsys'] ][$vlan->getNumber()][$proto] = 2;
                                else if( $me['vlaninterfaces'][$vlan->getNumber()][0]['rsclient'] && $c['vlaninterfaces'][$vlan->getNumber()][0]['rsclient'] )
                                {
                                    $custs[ $c['autsys'] ][$vlan->getNumber()][$proto] = 1;
                                    $custs[ $c['autsys' ] ]['ispotential'] = true;
                                }
                                else
                                {
                                    $custs[ $c['autsys'] ][$vlan->getNumber()][$proto] = 0;
                                    $custs[ $c['autsys' ] ]['ispotential'] = true;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        foreach( $custs as $c )
        {
            $peered[          $c['autsys' ] ] = false;
            $potential_bilat[ $c['autsys' ] ] = false;
            $potential[       $c['autsys' ] ] = false;
            $rejected[        $c['autsys' ] ] = false;
            
            foreach( $vlans as $vlan )
            {
                foreach( $protos as $proto )
                {
                    if( isset( $c[$vlan->getNumber()][$proto] ) )
                    {
                        switch( $c[$vlan->getNumber()][$proto] )
                        {
                            case 2:
                                $peered[ $c['autsys' ] ] = true;
                                break;
                                
                            case 1:
                                $peered[          $c['autsys' ] ] = true;
                                $potential_bilat[ $c['autsys' ] ] = true;
                                break;
                                
                            case 0:
                                $potential[       $c['autsys' ] ] = true;
                                $potential_bilat[ $c['autsys' ] ] = true;
                                break;
                                
                        }
                    }
                }
            }
        }

        foreach( $custs as $c )
        {
            if( isset( $peers[ $c['id'] ] ) )
            {
                if( isset( $peers[ $c['id'] ]['peered'] ) && $peers[ $c['id'] ]['peered'] )
                {
                    $peered[ $c['autsys' ] ] = true;
                    $rejected[ $c['autsys' ] ] = false;
                    $potential[ $c['autsys' ] ] = false;
                    $potential_bilat[ $c['autsys' ] ] = false;
                }
                else if( isset( $peers[ $c['id'] ]['rejected'] ) && $peers[ $c['id'] ]['rejected'] )
                {
                    $peered[ $c['autsys' ] ] = false;
                    $rejected[ $c['autsys' ] ] = true;
                    $potential[ $c['autsys' ] ] = false;
                    $potential_bilat[ $c['autsys' ] ] = false;
                }
            }
        }
        
        $this->view->custs = $custs;
        
        $this->view->potential       = $potential;
        $this->view->potential_bilat = $potential_bilat;
        $this->view->peered          = $peered;
        $this->view->rejected        = $rejected;
        
        //echo '<pre>'; print_r( $custs ); die();
        
        $this->view->date = date( 'Y-m-d' );
    }




    public function peeringRequestAction()
    {
        $TESTMODE = true;
        
        $this->view->peer = $peer = $this->getD2EM()->getRepository( '\\Entities\\Customer' )->find( $this->getParam( 'custid', null ) );
        
        if( !$peer )
        {
            echo "ERR:Could not find peer's information in the database. Please contact support.";
            return true;
        }
        
        $f = new INEX_Form_PeeringRequest();
        
        // potential peerings
        $pp = array(); $count = 0;
        
        foreach( $this->getCustomer()->getVirtualInterfaces() as $myvis )
        {
            foreach( $myvis->getVlanInterfaces() as $myvli )
            {
                // does b member have one (or more than one)?
                foreach( $peer->getVirtualInterfaces() as $pvis )
                {
                    foreach( $pvis->getVlanInterfaces() as $pvli )
                    {
                        if( $myvli->getVlan()->getId() == $pvli->getVlan()->getId() )
                        {
                            $pp[$count]['my']   = $myvli;
                            $pp[$count]['your'] = $pvli;
                            $count++;
                        }
                    }
                }
            }
        }
        
        // INEX_Debug::dd( $pp );
        $this->view->pp = $pp;
        
        $f->getElement( 'to' )->setValue( $peer->getPeeringemail() );
        $f->getElement( 'cc' )->setValue( $this->getCustomer()->getPeeringemail() );

        if( $this->getRequest()->isPost() )
        {
            if( $f->isValid( $_POST ) )
            {
                $sendtome = $f->getValue( 'sendtome' ) == '1' ? true : false;
                $marksent = $f->getValue( 'marksent' ) == '1' ? true : false;
                
                $bccOk = true;
                $bcc = [];
                if( !$sendtome )
                {
                    if( strlen( $bccs = $f->getValue( 'bcc' ) ) )
                    {
                        foreach( explode( ',', $bccs ) as $b )
                        {
                            $b = trim( $b );
                            if( !Zend_Validate::is( $b, 'EmailAddress' ) )
                            {
                                $f->getElement( 'bcc' )->addError( 'One or more email address(es) here are invalid' );
                                $bccOk = false;
                            }
                            else
                                $bcc[] = $b;
                        }
                    }
                }
                                
                if( $bccOk )
                {
                
                    $mail = new Zend_Mail();
                    $mail->setFrom( 'no-reply@inex.ie', $this->getCustomer()->getName() . ' Peering Team' )
                         ->setReplyTo( $this->getCustomer()->getPeeringemail(), $this->getCustomer()->getName() . ' Peering Team' )
                         ->setSubject( $f->getValue( 'subject' ) )
                         ->setBodyText( $f->getValue( 'message' ) );

                    if( $sendtome )
                    {
                        $mail->addTo( $this->getUser()->getEmail() );
                    }
                    else
                    {
                        $mail->addTo( $TESTMODE ? 'barryo@inex.ie' : $peer->getPeeringemail(), "{$peer->getName()} Peering Team" )
                             ->addCc( $TESTMODE ? 'barryo@inex.ie' : $this->getCustomer()->getPeeringemail(), "{$this->getCustomer()->getName()} Peering Team" );
                    }
                    
                    if( count( $bcc ) )
                        foreach( $bcc as $b )
                            $mail->addBcc( $b );
                    
                    try {
                        if( !$marksent )
                            $mail->send();
                        
                        if( !$sendtome )
                        {
                            // get this customer/peer peering manager table entry
                            $pm = $this->getD2EM()->getRepository( '\\Entities\\PeeringManager' )->findOneBy(
                                [ 'Customer' => $this->getCustomer(), 'Peer' => $peer ]
                            );
                            
                            if( !$pm )
                            {
                                $pm = new \Entities\PeeringManager();
                                $pm->setCustomer( $this->getCustomer() );
                                $pm->setPeer( $peer );
                                $pm->setCreated( new DateTime() );
                                $pm->setPeered( false );
                                $pm->setRejected( false );
                                $this->getD2EM()->persist( $pm );
                            }
                            
                            $pm->setEmailLastSent( new DateTime() );
                            $pm->setEmailsSent( $pm->getEmailsSent() + 1 );
                            $pm->setUpdated( new DateTime() );
                            $pm->setNotes(
                                date( 'Y-m-d' ) . " [{$this->getUser()->getUsername()}]: peering request " . ( $marksent ? 'marked ' : '' ) . "sent\n\n" . $pm->getNotes()
                            );
                                                                
                            $this->getD2EM()->flush();
                        }
                    }
                    catch( Zend_Exception $e )
                    {
                        $this->getLogger()->err( $e->getMessage() . "\n\n" . $e->getTraceAsString() );
                        echo "ERR:Could not send the peering email. Please send manually yourself or contact support.";
                        return true;
                    }
                    
                    if( $sendtome )
                        echo "OK:Peering request sample sent to your own email address ({$this->getUser()->getEmail()}).";
                    else if( $marksent )
                        echo "OK:Peering request marked as sent in your Peering Manager.";
                    else
                        echo "OK:Peering request sent to {$peer->getName()} Peering Team.";
                    
                    return true;
                }
            }
        }
        else
        {
            $f->getElement( 'bcc' )->setValue( $this->getUser()->getEmail() );
            $f->getElement( 'subject' )->setValue( "[INEX] Peering Request from {$this->getCustomer()->getName()} (ASN{$this->getCustomer()->getAutsys()})" );
            $f->getElement( 'message' )->setValue( $this->view->render( 'peering-manager/peering-request-message.phtml' ) );
        }
        
        $this->view->form = $f;
    }
    
    public function peeringNotesAction()
    {
        $this->view->peer = $peer = Doctrine_Core::getTable( 'Cust' )->find( $this->_request->getParam( 'custid', null ) );
    
        if( !$peer )
        {
            echo "ERR:Could not find peer's information in the database. Please contact support.";
            return true;
        }

        // get this customer/peer peering manager table entry
        $pm = PeeringManagerTable::getEntry( $this->getCustomer()['id'], $peer['id'] );

        if( $this->getRequest()->isPost() )
        {
            $pm['updated'] = date( 'Y-m-d H:i:s' );
            
            if( trim( stripslashes( $this->_getParam( 'message', '' ) ) ) )
                $pm['notes'] = trim( stripslashes( $this->_getParam( 'message' ) ) );
            
            try
            {
                $pm->save();
            }
            catch( Exception $e )
            {
                $this->getLogger()->err( $e->getMessage() . "\n\n" . $e->getTraceAsString() );
                echo "ERR:Could not update peering notes due to an unexpected error.";
                return true;
            }
            
            echo "OK:Peering notes updated for {$peer['name']}.";
            return true;
        }
        else
        {
            echo 'OK:' . $pm['notes'];
        }
        return true;
    }
    
    
    public function markPeeredAction()
    {
        $this->view->peer = $peer = Doctrine_Core::getTable( 'Cust' )->find( $this->_request->getParam( 'custid', null ) );
    
        if( !$peer )
        {
            $this->view->message = new INEX_Message( 'Invalid / unknown peer specified', INEX_Message::MESSAGE_TYPE_ERROR );
            return $this->_forward( 'index' );
        }

        // get this customer/peer peering manager table entry
        $pm = PeeringManagerTable::getEntry( $this->getCustomer()['id'], $peer['id'] );
    
        $pm['peered'] = $pm['peered'] ? 0 : 1;
        if( $pm['peered'] && $pm['rejected'] )
            $pm['rejected'] = 0;
        
        $pm->save();
        
        $this->session->message = new INEX_Message( "Peered flag " . ( $pm['peered'] ? 'set' : 'cleared' ) . " for {$peer['name']}.",
            INEX_Message::MESSAGE_TYPE_SUCCESS
        );
        return $this->_redirect( 'peering-manager/index' );
    }
    
    
    public function markRejectedAction()
    {
        $this->view->peer = $peer = Doctrine_Core::getTable( 'Cust' )->find( $this->_request->getParam( 'custid', null ) );
    
        if( !$peer )
        {
            $this->view->message = new INEX_Message( 'Invalid / unknown peer specified', INEX_Message::MESSAGE_TYPE_ERROR );
            return $this->_forward( 'index' );
        }
    
        // get this customer/peer peering manager table entry
        $pm = PeeringManagerTable::getEntry( $this->getCustomer()['id'], $peer['id'] );
    
        $pm['rejected'] = $pm['rejected'] ? 0 : 1;
        if( $pm['peered'] && $pm['rejected'] )
            $pm['peered'] = 0;
    
        $pm->save();
    
        $this->session->message = new INEX_Message( "Ignored / rejected flag " . ( $pm['rejected'] ? 'set' : 'cleared' ) . " for {$peer['name']}.",
            INEX_Message::MESSAGE_TYPE_SUCCESS
        );
        return $this->_redirect( 'peering-manager/index' );
    }
    
    
    
    

}

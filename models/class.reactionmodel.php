<?php if (!defined('APPLICATION')) exit();
/* Copyright 2013 Zachary Doll */

/**
 * Reactions are the actions a user takes against another user's content
 * 
 * Events: AfterReactionSave
 * 
 * @package Yaga
 * @since 1.0
 */

class ReactionModel extends Gdn_Model {
  
  /**
   * Used to cache the reactions
   * @var array
   */
  private static $_Reactions = array();
  
  /**
   * Used as a cache for the available actions
   * @var DataSet
   */
  private static $_Actions = NULL;
  
  /**
   * Defines the related database table name.
   */
  public function __construct() {
    parent::__construct('Reaction');
  }

  /**
   * Returns a list of all available actions.
   * 
   * @return DataSet
   */
  public function GetActions() {
    if(empty(self::$_Actions)) {
      self::$_Actions = $this->SQL
              ->Select()
              ->From('Action')
              ->OrderBy('ActionID')
              ->Get()
              ->Result();
    }
    return self::$_Actions;
  }
  
  /**
   * Returns data for a specific action
   * 
   * @param int $ActionID
   * @return DataSet
   */
  public function GetActionID($ActionID) {
    return $this->SQL
                    ->Select()
                    ->From('Action')
                    ->Where('ActionID', $ActionID)
                    ->Get()
                    ->FirstRow();
  }

  /**
   * Returns the reactions associated with the specified user content.
   * 
   * @param int $ID
   * @param enum $Type is the kind of ID. Valid: comment, discussion, activity
   */
  public function GetReactions($ID, $Type) {
    if(in_array($Type, array('discussion', 'comment', 'activity')) && $ID > 0) {
      $ReactionSet = array();
      if(empty(self::$_Reactions[$Type . $ID])) {
        foreach($this->GetActions() as $Index => $Action) {
          $ReactionSet[$Index]->ActionID = $Action->ActionID;
          $ReactionSet[$Index]->Name = $Action->Name;
          $ReactionSet[$Index]->Description = $Action->Description;
          $ReactionSet[$Index]->Tooltip = $Action->Tooltip;
          $ReactionSet[$Index]->CssClass = $Action->CssClass;
          $ReactionSet[$Index]->AwardValue = $Action->AwardValue;
          
          $Reactions = $this->SQL
                  ->Select('InsertUserID as UserID, DateInserted')
                  ->From('Reaction')
                  ->Where('ActionID', $Action->ActionID)
                  ->Where('ParentID', $ID)
                  ->Where('ParentType', $Type)
                  ->Get()
                  ->Result();
          
          foreach($Reactions as $Reaction) {
            $ReactionSet[$Index]->UserIDs[] = $Reaction->UserID;
            $ReactionSet[$Index]->Dates[] = $Reaction->DateInserted;
          }
          if(empty($ReactionSet[$Index]->UserIDs)) {
            $ReactionSet[$Index]->UserIDs = array();
          }
            
        }

        self::$_Reactions[$Type . $ID] = $ReactionSet;
      }
      return self::$_Reactions[$Type . $ID];
    }
    else {
      return NULL;
    }
  }

  /**
   * Return a list of reactions a user has received
   * 
   * @param int $ID
   * @param enum $Type activity, comment, discussion
   * @param int $UserID
   * @return DataSet
   */
  public function GetUserReaction($ID, $Type, $UserID) {
    return $this->SQL
            ->Select()
            ->From('Reaction')
            ->Where('ParentID', $ID)
            ->Where('ParentType', $Type)
            ->Where('InsertUserID', $UserID)
            ->Get()
            ->FirstRow();
  }
  
  /**
   * Return the count of reactions received by a user
   * 
   * @param int $UserID
   * @param int $ActionID
   * @return DataSet
   */
  public function GetUserReactionCount($UserID, $ActionID) {
    return $this->SQL
            ->Select()
            ->From('Reaction')
            ->Where('ActionID', $ActionID)
            ->Where('ParentAuthorID', $UserID)
            ->GetCount();
  }
  
  /**
   * Sets a users reaction against another user's content. A user can only react
   * in one way to each unique piece of content. This function makes sure to
   * enforce this rule
   * 
   * Events: AfterReactionSave
   * 
   * @param int $ID
   * @param enum $Type activity, comment, discussion
   * @param int $AuthorID
   * @param int $UserID
   * @param int $ActionID
   * @return DataSet
   */
  public function SetReaction($ID, $Type, $AuthorID, $UserID, $ActionID) {
    // clear the cache
    unset(self::$_Reactions[$Type . $ID]);

    $EventArgs = array('ID' => $ID, 'Type' => $Type, 'UserID' => $AuthorID, 'InsertUserID' => $UserID, 'Action' => $ActionID);
    
    $CurrentReaction = $this->GetUserReaction($ID, $Type, $UserID);
    if($CurrentReaction) {
      if($ActionID == $CurrentReaction->ActionID) {
        // remove the record
        $Reaction = $this->SQL->Delete('Reaction', array('ParentID' => $ID,
                    'ParentType' => $Type,
                    'InsertUserID' => $UserID,
                    'ActionID' => $ActionID));
        $EventArgs['Exists'] = FALSE;
      }
      else {
        // update the record
        $Reaction = $this->SQL
              ->Update('Reaction')
              ->Set('ActionID', $ActionID)
              ->Set('DateInserted', date(DATE_ISO8601))
              ->Where('ParentID', $ID)
              ->Where('ParentType', $Type)
              ->Where('InsertUserID', $UserID)
              ->Put();
        $EventArgs['Exists'] = TRUE;
      }
    }
    else {
      // insert a record
      $Reaction = $this->SQL
              ->Insert('Reaction',
                      array('ActionID' => $ActionID,
                      'ParentID' =>  $ID,
                      'ParentType' => $Type,
                      'ParentAuthorID' => $AuthorID,
                      'InsertUserID' => $UserID,
                      'DateInserted' => date(DATE_ISO8601)));
      $EventArgs['Exists'] = TRUE;
    }
    
    $this->FireEvent('AfterReactionSave', $EventArgs);
    return $Reaction;
  }
}
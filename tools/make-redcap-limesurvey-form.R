#!/usr/bin/Rscript
# Create REDCap stub instrument for Limesurvey forms

# set the name of the instrument
instrument = "SCID-5-PD"

# create form name
name = gsub(" ", "_", tolower(instrument))
name = gsub("[^a-z_0-9]", "", name)

form = data.frame(
  "Variable / Field Name" = paste(name, 
                                  c("validfrom", "validuntil", "state", "startdate", "submitdate"),
                                  sep = "_"),
  "Form Name" = name,
  "Section Header" = c("LimeSurvey Instrument",
                       "", "", "", ""),
  "Field Type" = c("text", "text", "dropdown", "text", "text"),
  "Field Label" = c("Gültig ab", "Gültig bis",
                    "Status", "Begonnen am", "Beendet am"),
  "Choices, Calculations, OR Slider Labels" = c("", "",
                                                "1, neu | 2, aktiviert | 3, beendet | 4, abgelaufen",
                                                "", ""),
  "Field Note" = "",
  "Text Validation Type OR Show Slider Number" = c("datetime_seconds_dmy", "datetime_seconds_dmy",
                                                     "", "datetime_seconds_dmy", "datetime_seconds_dmy"),
  "Text Validation Min" = "",
  "Text Validation Max" = "",
  "Identifier?" = "",
  "Branching Logic (Show field only if...)" = "",
  "Required Field?" = "",
  "Custom Alignment" = "",
  "Question Number (surveys only)" = "",
  "Matrix Group Name" = "",
  "Matrix Ranking?" = "",
  "Field Annotation" = c("@NOW", "", "@READONLY @DEFAULT='1'", "@READONLY", "@READONLY"))

write.csv(form, "instrument.csv", row.names = FALSE, na = "")
zip(instrument, "instrument.csv")
file.remove("instrument.csv")
